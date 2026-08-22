<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
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
    }

    /**
     * Exchange a valid, unexpired refresh token for a new access/refresh token pair.
     * The consumed refresh token and its paired access token are revoked (rotation).
     *
     * @return array{0: User, 1: CookieObject, 2: CookieObject}|null
     */
    public function rotate(string $plainRefreshToken): ?array
    {
        $refreshToken = RefreshToken::whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('token', hash('sha256', $plainRefreshToken))
            ->first();

        if (! $refreshToken || ! $refreshToken->user) {
            return null;
        }

        $user = $refreshToken->user;

        $refreshToken->update(['revoked_at' => now()]);
        $user->tokens()->find($refreshToken->access_token_id)?->revoke();

        return [$user, ...$this->issue($user)];
    }

    /**
     * Revoke the refresh token(s) tied to the given access token id (used on logout).
     */
    public function revokeForAccessToken(string $accessTokenId): void
    {
        RefreshToken::whereNull('revoked_at')
            ->where('access_token_id', $accessTokenId)
            ->update(['revoked_at' => now()]);
    }

    /**
     * Revoke every outstanding refresh token for a user (used on password reset).
     */
    public function revokeAllForUser(User $user): void
    {
        RefreshToken::where('user_id', $user->id)->delete();
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
