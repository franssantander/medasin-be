<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class GoogleAuthService
{
    private const FLOW_KEY = 'google_oauth';

    private const FLOW_LIFETIME_SECONDS = 600;

    public function __construct(
        private readonly TokenService $tokens,
        private readonly SocialiteManager $socialite,
    ) {}

    public function begin(Request $request, bool $rememberMe, ?User $linkUser = null): RedirectResponse
    {
        $this->assertConfigured();
        $accessTokenId = null;

        if ($linkUser) {
            $token = $linkUser->token();
            $accessTokenId = match (true) {
                $token instanceof AccessToken => (string) $token->oauth_access_token_id,
                $token instanceof Model => (string) $token->getKey(),
                default => null,
            };

            if ($accessTokenId === null) {
                throw $this->linkSessionExpired();
            }
        }

        $session = $request->session();
        $session->forget([self::FLOW_KEY, 'state', 'code_verifier']);
        $session->regenerate(true);
        $session->put(self::FLOW_KEY, [
            'expires_at' => now()->getTimestamp() + self::FLOW_LIFETIME_SECONDS,
            'remember_me' => $rememberMe,
            'user_id' => $linkUser?->getKey(),
            'access_token_id' => $accessTokenId,
        ]);

        return $this->provider($request)->redirect();
    }

    /** @return array{0: User, 1: Cookie, 2: Cookie, 3: bool} */
    public function complete(Request $request): array
    {
        $session = $request->session();
        $flow = $session->pull(self::FLOW_KEY);
        $state = $session->get('state');
        $incomingState = $request->query('state');

        if (! is_array($flow)
            || ! is_int($flow['expires_at'] ?? null)
            || $flow['expires_at'] <= now()->getTimestamp()
            || ! is_string($state) || $state === ''
            || ! is_string($incomingState) || ! hash_equals($state, $incomingState)) {
            $session->forget(['state', 'code_verifier']);
            $session->save();

            throw $this->invalidState();
        }

        $session->save();

        try {
            if ($request->query->has('error')) {
                throw new GoogleAuthException(
                    $request->query('error') === 'access_denied' ? 'GOOGLE_OAUTH_CANCELLED' : 'GOOGLE_AUTH_FAILED',
                    'Google sign-in was not completed. Please try again.',
                );
            }

            $code = $request->query('code');

            if (! is_string($code) || trim($code) === '') {
                throw new GoogleAuthException('GOOGLE_OAUTH_INVALID_CALLBACK', 'The Google callback is invalid. Please start sign-in again.');
            }

            $this->assertConfigured();
            $profile = $this->provider($request)->user();
        } catch (InvalidStateException) {
            throw $this->invalidState();
        } catch (GoogleAuthException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Unable to retrieve the Google sign-in profile.', ['exception' => $exception::class]);

            throw GoogleAuthException::unavailable();
        } finally {
            $session->forget(['state', 'code_verifier']);
            $session->save();
        }

        $attributes = $this->profileAttributes($profile);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $flow): array {
                    $isLinking = $flow['user_id'] !== null;
                    $user = $isLinking
                        ? $this->linkAccount($attributes, $flow['user_id'], $flow['access_token_id'])
                        : $this->signInAccount($attributes);

                    if (! $user->hasVerifiedEmail()) {
                        throw new GoogleAuthException('EMAIL_VERIFICATION_REQUIRED', 'Please verify your email address before accessing the app.', 403);
                    }

                    return [$user, ...$this->tokens->issue($user, $flow['remember_me']), $isLinking];
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    Log::warning('Unable to resolve a Google account registration conflict.', ['exception' => $exception::class]);

                    throw GoogleAuthException::unavailable();
                }
            }
        }

        throw GoogleAuthException::unavailable();
    }

    public function frontendRedirectUri(): ?string
    {
        $uri = config('services.google.frontend_redirect');

        return $this->isRedirectUri($uri) ? $uri : null;
    }

    private function provider(Request $request): GoogleProvider
    {
        return $this->socialite->buildProvider(GoogleProvider::class, config('services.google'))
            ->setRequest($request)
            ->setScopes(['openid', 'profile', 'email'])
            ->with(['prompt' => 'select_account']);
    }

    private function assertConfigured(): void
    {
        foreach (['client_id', 'client_secret'] as $key) {
            $value = config('services.google.'.$key);

            if (! is_string($value) || trim($value) === '') {
                throw GoogleAuthException::unavailable();
            }
        }

        $callbackUri = config('services.google.redirect');
        $frontendUri = config('services.google.frontend_redirect');

        if (! $this->isRedirectUri($callbackUri)
            || parse_url($callbackUri, PHP_URL_PATH) !== route('auth.google.callback', [], false)
            || parse_url($callbackUri, PHP_URL_QUERY) !== null
            || parse_url($callbackUri, PHP_URL_FRAGMENT) !== null
            || ($frontendUri !== null && $frontendUri !== '' && ! $this->isRedirectUri($frontendUri))) {
            throw GoogleAuthException::unavailable();
        }
    }

    private function isRedirectUri(mixed $uri): bool
    {
        if (! is_string($uri) || ! filter_var($uri, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($uri);

        return is_array($parts)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && in_array($parts['scheme'] ?? '', app()->isProduction() ? ['https'] : ['http', 'https'], true);
    }

    /** @return array{google_id: string, email: string, first_name: string, last_name: string} */
    private function profileAttributes(#[\SensitiveParameter] GoogleUser $profile): array
    {
        $raw = $profile->getRaw();
        $attributes = ['google_id' => $profile->getId(), 'email' => $profile->getEmail()];

        if (($raw['email_verified'] ?? false) !== true || Validator::make($attributes, [
            'google_id' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ])->fails()) {
            throw new GoogleAuthException('GOOGLE_PROFILE_INVALID', 'Google must provide a valid account and verified email address.', 422);
        }

        return [
            'google_id' => $attributes['google_id'],
            'email' => Str::lower($attributes['email']),
            'first_name' => $this->name($raw['given_name'] ?? null) ?: ($this->name($profile->getName()) ?: 'Google User'),
            'last_name' => $this->name($raw['family_name'] ?? null),
        ];
    }

    private function name(mixed $name): string
    {
        return is_string($name) ? Str::substr(trim($name), 0, 255) : '';
    }

    /** @param array{google_id: string, email: string, first_name: string, last_name: string} $attributes */
    private function signInAccount(array $attributes): User
    {
        $user = User::query()->where('google_id', $attributes['google_id'])->lockForUpdate()->first();

        if ($user) {
            return $user;
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$attributes['email']])->exists()) {
            throw new GoogleAuthException(
                'GOOGLE_ACCOUNT_LINK_REQUIRED', 'Sign in to your existing account before linking Google.', 409,
            );
        }

        $user = new User([
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'email' => $attributes['email'],
            'username' => 'google_'.Str::lower(Str::random(16)),
            'password' => Str::random(64),
        ]);
        $user->forceFill(['google_id' => $attributes['google_id'], 'email_verified_at' => now()])->saveOrFail();
        event(new Registered($user));
        event(new Verified($user));

        return $user->refresh();
    }

    /** @param array{google_id: string, email: string, first_name: string, last_name: string} $attributes */
    private function linkAccount(array $attributes, int $userId, string $accessTokenId): User
    {
        $user = User::query()->lockForUpdate()->find($userId);
        $accessToken = $user?->tokens()->with('client')->whereKey($accessTokenId)
            ->where('revoked', false)->where('expires_at', '>', now())->first();
        $client = $accessToken?->client;

        if (! $user || ! $user->hasVerifiedEmail() || ! $client || $client->revoked
            || ($client->provider && $client->provider !== config('auth.guards.api.provider'))) {
            throw $this->linkSessionExpired();
        }

        if (Str::lower($user->email) !== $attributes['email']) {
            throw new GoogleAuthException('GOOGLE_EMAIL_MISMATCH', 'Use the Google account with the same email address as your existing account.', 422);
        }

        if (($user->google_id !== null && $user->google_id !== $attributes['google_id'])
            || User::query()->where('google_id', $attributes['google_id'])->where('id', '!=', $userId)->exists()) {
            throw new GoogleAuthException('GOOGLE_ACCOUNT_ALREADY_LINKED', 'This Google account cannot be linked to your account.', 409);
        }

        if ($user->google_id === null) {
            $user->forceFill(['google_id' => $attributes['google_id']])->saveOrFail();
        }

        return $user;
    }

    private function invalidState(): GoogleAuthException
    {
        return new GoogleAuthException('GOOGLE_OAUTH_INVALID_STATE', 'The Google sign-in request is invalid or has expired. Please start again.');
    }

    private function linkSessionExpired(): GoogleAuthException
    {
        return new GoogleAuthException('GOOGLE_LINK_SESSION_EXPIRED', 'Sign in to your existing account again before linking Google.', 401);
    }
}
