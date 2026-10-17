<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use App\Notifications\AccountDeletionNotification;
use App\Notifications\SignedOutEverywhereNotification;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Cookie as CookieObject;

class TokenService
{
    /** Access tokens are short-lived; browsers renew them through /auth/refresh on a 401. */
    public const ACCESS_TOKEN_MINUTES = 60;

    /** A rotated refresh token presented within this window is a concurrent-tab race, not theft. */
    public const REUSE_GRACE_SECONDS = 30;

    protected string $accessCookieName = 'auth_token';

    protected string $refreshCookieName = 'refresh_token';

    protected int $refreshTokenDays = 30; // matches Passport::refreshTokensExpireIn() in AppServiceProvider

    /**
     * Issue a fresh access token + refresh token pair for the user, as cookies.
     *
     * @return array{0: CookieObject, 1: CookieObject}
     */
    public function issue(User $user, bool $rememberMe = true): array
    {
        return DB::transaction(function () use ($user, $rememberMe): array {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $user->hasVerifiedEmail()) {
                abort(403, 'Please verify your email address before accessing the app.');
            }

            $tokenResult = $user->createToken('auth_token');
            $plainRefreshToken = Str::random(64);

            RefreshToken::create([
                'user_id' => $user->id,
                'access_token_id' => $tokenResult->token->id,
                'token' => hash('sha256', $plainRefreshToken),
                'expires_at' => now()->addDays($this->refreshTokenDays),
                'remember_me' => $rememberMe,
            ]);

            // Signing in again is how an owner keeps an account that is scheduled for deletion.
            if ($user->deletion_scheduled_at !== null) {
                $user->forceFill(['deletion_scheduled_at' => null])->saveOrFail();
                $user->notify(new AccountDeletionNotification(AccountDeletionNotification::CANCELLED));
            }

            return [
                $this->makeCookie($this->accessCookieName, $tokenResult->accessToken, $rememberMe ? self::ACCESS_TOKEN_MINUTES : 0, '/'),
                $this->makeCookie($this->refreshCookieName, $plainRefreshToken, $rememberMe ? 60 * 24 * $this->refreshTokenDays : 0, '/api/v1/auth'),
            ];
        });
    }

    /**
     * Exchange a valid, unexpired refresh token for a new access/refresh token pair.
     * The consumed refresh token and its paired access token are revoked (rotation).
     * Presenting a token that was already rotated (outside a short race window) means it was copied,
     * so every session for the account is revoked and the owner is told.
     *
     * @return array{0: User, 1: CookieObject, 2: CookieObject}|null
     */
    public function rotate(#[\SensitiveParameter] string $plainRefreshToken): ?array
    {
        $tokenHash = hash('sha256', $plainRefreshToken);
        $userId = RefreshToken::query()->where('token', $tokenHash)->value('user_id');

        if (! $userId) {
            return null;
        }

        $reused = false;
        $result = DB::transaction(function () use ($userId, $tokenHash, &$reused): ?array {
            $user = User::query()->lockForUpdate()->find($userId);

            if (! $user || ! $user->hasVerifiedEmail()) {
                return null;
            }

            $refreshToken = RefreshToken::query()
                ->where('user_id', $user->id)
                ->where('token', $tokenHash)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $refreshToken) {
                return null;
            }

            if ($refreshToken->revoked_at !== null) {
                if ($refreshToken->rotated_at?->lt(now()->subSeconds(self::REUSE_GRACE_SECONDS))) {
                    $reused = true;
                    $this->revokeAllForUser($user);
                    $user->notify(new SignedOutEverywhereNotification);
                }

                return null;
            }

            $refreshToken->update(['revoked_at' => now(), 'rotated_at' => now()]);
            $accessToken = $user->tokens()->find($refreshToken->access_token_id);
            $accessToken?->refreshToken()->update(['revoked' => true]);
            $accessToken?->revoke();

            return [$user, ...$this->issue($user, $refreshToken->remember_me)];
        });

        if ($reused) {
            Log::warning('Rotated refresh token reused; revoked every session for the account.', ['user_id' => $userId]);
        }

        return $result;
    }

    /**
     * Revoke an access token and its refresh tokens (used on logout).
     */
    public function revokeForAccessToken(User $user, string $accessTokenId): void
    {
        DB::transaction(function () use ($user, $accessTokenId): void {
            $user = User::query()->lockForUpdate()->find($user->id);

            if (! $user) {
                return;
            }

            $accessToken = $user->tokens()->find($accessTokenId);
            $accessToken?->refreshToken()->update(['revoked' => true]);
            $accessToken?->revoke();
            RefreshToken::query()->where('user_id', $user->id)
                ->where('access_token_id', $accessTokenId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        });
    }

    /**
     * Revoke every access and refresh token for a user (used on password reset).
     */
    public function revokeAllForUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user = User::query()->lockForUpdate()->find($user->id);

            if (! $user) {
                return;
            }

            $tokenIds = $user->tokens()->pluck('id');
            Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', $tokenIds)
                ->update(['revoked' => true]);
            $user->tokens()->update(['revoked' => true]);
            RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        });
    }

    public function forgetCookies(): array
    {
        return [
            Cookie::forget($this->accessCookieName, '/'),
            Cookie::forget($this->refreshCookieName, '/api/v1/auth'),
        ];
    }

    protected function makeCookie(string $name, string $value, int $minutes, string $path): CookieObject
    {
        return cookie(
            $name,
            $value,
            $minutes,
            $path,
            null,
            app()->environment('production'),
            true,
            false,
            'Strict'
        );
    }
}
