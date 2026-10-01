<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Cookie as CookieObject;

class TokenService
{
    protected string $accessCookieName = 'auth_token';

    protected string $refreshCookieName = 'refresh_token';

    protected int $accessTokenDays = 7;

    protected int $refreshTokenDays = 30; // matches Passport::refreshTokensExpireIn() in AppServiceProvider

    /**
     * Issue a fresh access token + refresh token pair for the user, as cookies.
     *
     * @return array{0: CookieObject, 1: CookieObject}
     */
    public function issue(User $user): array
    {
        return DB::transaction(function () use ($user): array {
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
            ]);

            return [
                $this->makeCookie($this->accessCookieName, $tokenResult->accessToken, $this->accessTokenDays, '/'),
                $this->makeCookie($this->refreshCookieName, $plainRefreshToken, $this->refreshTokenDays, '/api/v1/auth'),
            ];
        });
    }

    /**
     * Exchange a valid, unexpired refresh token for a new access/refresh token pair.
     * The consumed refresh token and its paired access token are revoked (rotation).
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

        return DB::transaction(function () use ($userId, $tokenHash): ?array {
            $user = User::query()->lockForUpdate()->find($userId);

            if (! $user || ! $user->hasVerifiedEmail()) {
                return null;
            }

            $refreshToken = RefreshToken::query()
                ->where('user_id', $user->id)
                ->where('token', $tokenHash)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $refreshToken) {
                return null;
            }

            $refreshToken->update(['revoked_at' => now()]);
            $accessToken = $user->tokens()->find($refreshToken->access_token_id);
            $accessToken?->refreshToken()->update(['revoked' => true]);
            $accessToken?->revoke();

            return [$user, ...$this->issue($user)];
        });
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

    protected function makeCookie(string $name, string $value, int $days, string $path): CookieObject
    {
        return cookie(
            $name,
            $value,
            60 * 24 * $days,
            $path,
            null,
            app()->environment('production'),
            true,
            false,
            'Strict'
        );
    }
}
