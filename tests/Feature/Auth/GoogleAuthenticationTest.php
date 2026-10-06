<?php

use App\Enum\AuthOtpPurpose;
use App\Models\User;
use App\Notifications\AuthOtpNotification;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as ProviderRequest;
use GuzzleHttp\Psr7\Response as ProviderResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Psr\Http\Message\ResponseInterface;

pest()->group('pest-features');

beforeEach(function (): void {
    config([
        'services.google.client_id' => 'test-google-client',
        'services.google.client_secret' => 'test-google-secret',
        'services.google.redirect' => 'http://localhost/api/v1/auth/google/callback',
        'services.google.frontend_redirect' => null,
        'session.driver' => 'database',
        'session.lottery' => [0, 100],
    ]);
});

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function googleOAuthProfile(array $overrides = []): array
{
    return array_replace([
        'sub' => 'google-account-123',
        'email' => 'ada@gmail.com',
        'email_verified' => true,
        'given_name' => 'Ada',
        'family_name' => 'Lovelace',
        'name' => 'Ada Lovelace',
    ], $overrides);
}

/** @param array<string, mixed> $profile */
function fakeGoogleOAuth(array $profile): MockHandler
{
    $handler = new MockHandler([
        new ProviderResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'google-provider-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR)),
        new ProviderResponse(200, ['Content-Type' => 'application/json'], json_encode($profile, JSON_THROW_ON_ERROR)),
    ]);
    config(['services.google.guzzle.handler' => HandlerStack::create($handler)]);

    return $handler;
}

function freshGoogleOAuthRequest(): void
{
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
    Auth::shouldUse('web');
    test()->withoutHeader('Authorization');
}

/** @param array<string, mixed> $query @return array{state: string, cookie: string, response: TestResponse} */
function beginGoogleOAuth(array $query = []): array
{
    freshGoogleOAuthRequest();
    $response = test()->get(route('auth.google.redirect', $query))->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);

    return [
        'state' => $parameters['state'],
        'cookie' => $response->getCookie(config('session.cookie'), false)->getValue(),
        'response' => $response,
    ];
}

/** @return array{state: string, cookie: string, response: TestResponse, access_token_id: string} */
function beginGoogleOAuthLink(User $user): array
{
    $login = test()->postJson(route('auth.login'), [
        'username' => $user->username,
        'password' => 'password',
    ])->assertOk();
    freshGoogleOAuthRequest();
    $response = test()->withUnencryptedCookie('auth_token', $login->getCookie('auth_token', false)->getValue())
        ->postJson(route('auth.google.link'))->assertOk();
    parse_str(parse_url($response->json('data.authorization_url'), PHP_URL_QUERY), $parameters);

    return [
        'state' => $parameters['state'],
        'cookie' => $response->getCookie(config('session.cookie'), false)->getValue(),
        'response' => $response,
        'access_token_id' => $user->tokens()->sole()->getKey(),
    ];
}

/** @param array{state: string, cookie: string} $flow @param array<string, mixed> $query */
function completeGoogleOAuth(array $flow, array $query = []): TestResponse
{
    freshGoogleOAuthRequest();

    return test()->withUnencryptedCookies([
        config('session.cookie') => $flow['cookie'],
        'auth_token' => '',
    ])->getJson(route('auth.google.callback', array_replace([
        'state' => $flow['state'],
        'code' => 'google-authorization-code',
    ], $query)));
}

it('redirects to Google with browser state and identity scopes only', function (): void {
    config(['services.google.scopes' => ['https://www.googleapis.com/auth/gmail.readonly']]);
    $flow = beginGoogleOAuth();
    $response = $flow['response'];
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);

    expect(parse_url($response->headers->get('Location'), PHP_URL_HOST))->toBe('accounts.google.com');
    expect($parameters)->toMatchArray([
        'client_id' => 'test-google-client',
        'redirect_uri' => 'http://localhost/api/v1/auth/google/callback',
        'scope' => 'openid profile email',
        'response_type' => 'code',
        'prompt' => 'select_account',
    ]);
    expect($flow['state'])->not->toBeEmpty();
    $response->assertCookie(config('session.cookie'))->assertHeaderContains('Cache-Control', 'no-store')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $cookie = $response->getCookie(config('session.cookie'), false);
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
    expect($flow['cookie'])->not->toBe(session()->getId());
});

it('registers a verified Google user and issues usable Passport cookies without an OTP', function (): void {
    Notification::fake([AuthOtpNotification::class]);
    $this->createPassportClient();
    $handler = fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth();

    $response = completeGoogleOAuth($flow)->assertOk()->assertJsonPath('data.email', 'ada@gmail.com')
        ->assertJsonPath('data.first_name', 'Ada')->assertJsonPath('data.last_name', 'Lovelace')
        ->assertJsonMissingPath('data.google_id')->assertJsonMissingPath('data.password')
        ->assertPlainCookie('auth_token')->assertPlainCookie('refresh_token');

    $user = User::where('email', 'ada@gmail.com')->sole();
    expect($user->google_id)->toBe('google-account-123');
    expect($user->hasVerifiedEmail())->toBeTrue();
    expect($user->username)->toStartWith('google_');
    expect(password_get_info($user->password)['algoName'])->toBe('bcrypt');
    expect($user->toArray())->not->toHaveKey('google_id');
    expect($handler->count())->toBe(0);
    Notification::assertNothingSent();

    $this->forgetAuthenticatedUser();
    $this->withUnencryptedCookie('auth_token', $response->getCookie('auth_token', false)->getValue())
        ->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.id', $user->id);
    $this->assertDatabaseHas('refresh_tokens', ['user_id' => $user->id, 'remember_me' => true, 'revoked_at' => null]);
});

it('uses a fresh Google profile on subsequent sign ins and resolves the stable account ID', function (): void {
    $this->createPassportClient();
    $user = User::factory()->create(['google_id' => 'google-account-123', 'email' => 'local@example.com']);
    $original = $user->only(['first_name', 'last_name', 'email', 'username', 'password']);
    fakeGoogleOAuth(googleOAuthProfile());
    $first = completeGoogleOAuth(beginGoogleOAuth())->assertOk()->assertJsonPath('data.id', $user->id);
    fakeGoogleOAuth(googleOAuthProfile(['sub' => 'google-account-456', 'email' => 'grace@gmail.com']));

    $second = completeGoogleOAuth(beginGoogleOAuth())->assertOk()->assertJsonPath('data.email', 'grace@gmail.com');

    expect($second->json('data.id'))->not->toBe($first->json('data.id'));
    expect($user->fresh()->only(array_keys($original)))->toBe($original);
    $this->assertDatabaseCount('users', 2);
});

it('returns 409 and requires explicit linking for a matching existing email', function (bool $verified, string $email): void {
    $user = $verified ? User::factory()->create(['email' => $email]) : User::factory()->unverified()->create(['email' => $email]);
    $original = $user->fresh()->getAttributes();
    fakeGoogleOAuth(googleOAuthProfile());

    completeGoogleOAuth(beginGoogleOAuth())->assertConflict()->assertJsonPath('code', 'GOOGLE_ACCOUNT_LINK_REQUIRED')
        ->assertCookieMissing('auth_token')->assertCookieMissing('refresh_token');

    expect($user->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('oauth_access_tokens', 0);
    $this->assertDatabaseCount('refresh_tokens', 0);
})->with([
    'verified account' => [true, 'ada@gmail.com'],
    'unverified account' => [false, 'ada@gmail.com'],
    'case insensitive email' => [true, 'Ada@Gmail.com'],
]);

it('links Google to the initiating verified account and signs in with Google afterwards', function (): void {
    $this->createPassportClient();
    $user = User::factory()->create(['email' => 'ada@gmail.com']);
    $original = $user->only(['first_name', 'last_name', 'email', 'username', 'password']);
    fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuthLink($user);

    completeGoogleOAuth($flow)->assertOk()->assertJsonPath('data.id', $user->id)->assertPlainCookie('auth_token');

    expect($user->fresh()->google_id)->toBe('google-account-123');
    expect($user->fresh()->only(array_keys($original)))->toBe($original);
    fakeGoogleOAuth(googleOAuthProfile());
    completeGoogleOAuth(beginGoogleOAuth())->assertOk()->assertJsonPath('data.id', $user->id);
    $this->assertDatabaseCount('users', 1);
});

it('returns 401 when Google linking is started without authentication', function (): void {
    $this->postJson(route('auth.google.link'))->assertUnauthorized();

    $this->assertDatabaseCount('users', 0);
});

it('returns 403 when an unverified account starts Google linking', function (): void {
    $user = User::factory()->unverified()->create();
    Passport::actingAs($user);

    $this->postJson(route('auth.google.link'))->assertForbidden();

    expect($user->fresh()->google_id)->toBeNull();
});

it('returns 401 when the initiating link session expires or is revoked', function (string $reason): void {
    $this->createPassportClient();
    $user = User::factory()->create(['email' => 'ada@gmail.com']);
    fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuthLink($user);
    $user->tokens()->whereKey($flow['access_token_id'])->update(match ($reason) {
        'revoked' => ['revoked' => true],
        'expired' => ['expires_at' => now()->subSecond()],
    });

    completeGoogleOAuth($flow)->assertUnauthorized()->assertJsonPath('code', 'GOOGLE_LINK_SESSION_EXPIRED')
        ->assertCookieMissing('auth_token');

    expect($user->fresh()->google_id)->toBeNull();
    $this->assertDatabaseCount('oauth_access_tokens', 1);
})->with(['revoked', 'expired']);

it('returns 401 after logout while Google linking is pending', function (): void {
    $this->createPassportClient();
    $user = User::factory()->create(['email' => 'ada@gmail.com']);
    fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuthLink($user);

    $this->postJson(route('auth.logout'))->assertOk();
    completeGoogleOAuth($flow)->assertUnauthorized()->assertJsonPath('code', 'GOOGLE_LINK_SESSION_EXPIRED');

    expect($user->fresh()->google_id)->toBeNull();
    $this->assertDatabaseMissing('refresh_tokens', ['user_id' => $user->id, 'revoked_at' => null]);
});

it('returns 401 when the initiating Passport client becomes invalid', function (array $changes): void {
    $this->createPassportClient();
    $user = User::factory()->create(['email' => 'ada@gmail.com']);
    fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuthLink($user);
    $user->tokens()->sole()->client->update($changes);

    completeGoogleOAuth($flow)->assertUnauthorized()->assertJsonPath('code', 'GOOGLE_LINK_SESSION_EXPIRED');

    expect($user->fresh()->google_id)->toBeNull();
    $this->assertDatabaseCount('oauth_access_tokens', 1);
})->with([
    'revoked client' => [['revoked' => true]],
    'changed provider' => [['provider' => 'another-provider']],
]);

it('does not recreate a deleted account when a pending link callback arrives', function (): void {
    $this->createPassportClient();
    $user = User::factory()->create(['email' => 'ada@gmail.com']);
    fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuthLink($user);
    $user->delete();

    completeGoogleOAuth($flow)->assertUnauthorized()->assertJsonPath('code', 'GOOGLE_LINK_SESSION_EXPIRED');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('oauth_access_tokens', 1);
});

it('returns 422 when linking uses a different Google email', function (): void {
    $this->createPassportClient();
    $user = User::factory()->create(['email' => 'local@example.com']);
    fakeGoogleOAuth(googleOAuthProfile());

    completeGoogleOAuth(beginGoogleOAuthLink($user))->assertUnprocessable()->assertJsonPath('code', 'GOOGLE_EMAIL_MISMATCH');

    expect($user->fresh()->google_id)->toBeNull();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('oauth_access_tokens', 1);
});

it('returns 409 when linking would replace an existing Google identity', function (): void {
    $this->createPassportClient();
    $user = User::factory()->create(['email' => 'ada@gmail.com', 'google_id' => 'another-google-account']);
    fakeGoogleOAuth(googleOAuthProfile());

    completeGoogleOAuth(beginGoogleOAuthLink($user))->assertConflict()->assertJsonPath('code', 'GOOGLE_ACCOUNT_ALREADY_LINKED');

    expect($user->fresh()->google_id)->toBe('another-google-account');
    $this->assertDatabaseCount('oauth_access_tokens', 1);
});

it('returns 409 when the Google identity belongs to another local account', function (): void {
    $this->createPassportClient();
    $owner = User::factory()->create(['google_id' => 'google-account-123']);
    $user = User::factory()->create(['email' => 'ada@gmail.com']);
    fakeGoogleOAuth(googleOAuthProfile());

    completeGoogleOAuth(beginGoogleOAuthLink($user))->assertConflict()->assertJsonPath('code', 'GOOGLE_ACCOUNT_ALREADY_LINKED');

    expect($user->fresh()->google_id)->toBeNull();
    expect($owner->fresh()->google_id)->toBe('google-account-123');
    $this->assertDatabaseCount('users', 2);
});

it('can finish linking an already linked identity without duplicating the account', function (): void {
    $this->createPassportClient();
    $user = User::factory()->create(['email' => 'ada@gmail.com', 'google_id' => 'google-account-123']);
    fakeGoogleOAuth(googleOAuthProfile());

    completeGoogleOAuth(beginGoogleOAuthLink($user))->assertOk()->assertJsonPath('data.id', $user->id);

    $this->assertDatabaseCount('users', 1);
});

it('returns 400 without contacting Google for invalid callback state', function (mixed $state): void {
    $handler = fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth();

    completeGoogleOAuth($flow, ['state' => $state])->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_INVALID_STATE')
        ->assertCookieMissing('auth_token');

    expect($handler->count())->toBe(2);
    $this->assertDatabaseCount('users', 0);
    completeGoogleOAuth($flow)->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_INVALID_STATE');
})->with(['missing' => [null], 'mismatch' => ['different-state'], 'array' => [['bad-state']]]);

it('returns 400 when the browser session cookie is missing or tampered with', function (string $cookie): void {
    $handler = fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth();
    $flow['cookie'] = $cookie;

    completeGoogleOAuth($flow)->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_INVALID_STATE');

    expect($handler->count())->toBe(2);
    $this->assertDatabaseCount('users', 0);
})->with(['missing' => [''], 'tampered' => ['invalid-session-cookie']]);

it('returns 400 when Google state reaches its ten minute expiry', function (): void {
    $this->freezeTime();
    $handler = fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth();
    $this->travel(10)->minutes();

    completeGoogleOAuth($flow)->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_INVALID_STATE');

    expect($handler->count())->toBe(2);
    $this->assertDatabaseCount('users', 0);
});

it('returns 400 when a successful callback is replayed', function (): void {
    $this->createPassportClient();
    fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth();
    completeGoogleOAuth($flow)->assertOk();

    completeGoogleOAuth($flow)->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_INVALID_STATE')->assertCookieMissing('auth_token');

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('oauth_access_tokens', 1);
    $this->assertDatabaseCount('refresh_tokens', 1);
});

it('serializes callbacks with the database session lock without consuming a busy flow', function (): void {
    $this->createPassportClient();
    $handler = fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth();
    $sessionId = $flow['response']->getCookie(config('session.cookie'))->getValue();
    $lock = Cache::store('database')->lock('session:'.$sessionId, 30);
    expect($lock->get())->toBeTrue();

    try {
        completeGoogleOAuth($flow)->assertConflict()->assertJsonPath('code', 'GOOGLE_OAUTH_BUSY')->assertCookieMissing('auth_token');
        expect($handler->count())->toBe(2);
        $this->assertDatabaseCount('users', 0);
    } finally {
        $lock->release();
    }

    completeGoogleOAuth($flow)->assertOk();
    $this->assertDatabaseCount('oauth_access_tokens', 1);
});

it('invalidates a previous browser flow when a new Google sign in starts', function (): void {
    $handler = fakeGoogleOAuth(googleOAuthProfile());
    $first = beginGoogleOAuth();
    $this->withUnencryptedCookie(config('session.cookie'), $first['cookie']);
    $second = beginGoogleOAuth();

    completeGoogleOAuth($first)->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_INVALID_STATE');

    expect($second['state'])->not->toBe($first['state']);
    expect($handler->count())->toBe(2);
});

it('returns 400 and consumes state when Google authorization is cancelled', function (): void {
    $handler = fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth();

    completeGoogleOAuth($flow, ['error' => 'access_denied', 'error_description' => 'private-provider-data'])
        ->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_CANCELLED')->assertDontSee('private-provider-data');

    expect($handler->count())->toBe(2);
    completeGoogleOAuth($flow)->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_INVALID_STATE');
    $this->assertDatabaseCount('users', 0);
});

it('returns a sanitized error for a different Google authorization failure', function (): void {
    $handler = fakeGoogleOAuth(googleOAuthProfile());

    completeGoogleOAuth(beginGoogleOAuth(), ['error' => 'server_error', 'error_description' => 'private-provider-data'])
        ->assertBadRequest()->assertJsonPath('code', 'GOOGLE_AUTH_FAILED')->assertDontSee('private-provider-data');

    expect($handler->count())->toBe(2);
    $this->assertDatabaseCount('users', 0);
});

it('returns 400 when the authorization code is missing or malformed', function (mixed $code): void {
    $handler = fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth();

    completeGoogleOAuth($flow, ['code' => $code])->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_INVALID_CALLBACK');

    expect($handler->count())->toBe(2);
    completeGoogleOAuth($flow)->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_INVALID_STATE');
})->with(['missing' => [null], 'array' => [['bad-code']]]);

it('returns 422 for invalid Google identity or email data', function (array $overrides): void {
    fakeGoogleOAuth(googleOAuthProfile($overrides));

    completeGoogleOAuth(beginGoogleOAuth())->assertUnprocessable()->assertJsonPath('code', 'GOOGLE_PROFILE_INVALID')
        ->assertCookieMissing('auth_token');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('oauth_access_tokens', 0);
})->with([
    'missing account ID' => [['sub' => null]],
    'oversized account ID' => [['sub' => str_repeat('1', 256)]],
    'invalid email' => [['email' => 'invalid-email']],
    'missing email' => [['email' => null]],
    'unverified email' => [['email_verified' => false]],
    'missing verification' => [['email_verified' => null]],
    'string verification' => [['email_verified' => 'false']],
]);

it('returns 403 rather than issuing tokens for an unverified linked local account', function (): void {
    $user = User::factory()->unverified()->create(['google_id' => 'google-account-123']);
    fakeGoogleOAuth(googleOAuthProfile());

    completeGoogleOAuth(beginGoogleOAuth())->assertForbidden()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    $this->assertDatabaseCount('oauth_access_tokens', 0);
});

it('creates a Google account even when the profile has no family name', function (): void {
    $this->createPassportClient();
    fakeGoogleOAuth(googleOAuthProfile(['given_name' => null, 'family_name' => null, 'name' => 'Prince']));

    completeGoogleOAuth(beginGoogleOAuth())->assertOk()->assertJsonPath('data.first_name', 'Prince')->assertJsonPath('data.last_name', '');

    $this->assertDatabaseHas('users', ['email' => 'ada@gmail.com', 'first_name' => 'Prince', 'last_name' => '']);
});

it('retries a generated username collision without creating duplicate users', function (): void {
    $this->createPassportClient();
    $taken = 'aaaaaaaaaaaaaaaa';
    $available = 'bbbbbbbbbbbbbbbb';
    User::factory()->create(['username' => 'google_'.$taken]);
    $usernames = [$taken, $available];
    fakeGoogleOAuth(googleOAuthProfile());
    $providerRequests = 0;
    config('services.google.guzzle.handler')->push(Middleware::mapResponse(function (ResponseInterface $response) use (&$providerRequests, &$usernames): ResponseInterface {
        if (++$providerRequests === 2) {
            Str::createRandomStringsUsing(function (int $length) use (&$usernames): string {
                return $length === 16 && $usernames !== []
                    ? array_shift($usernames)
                    : substr(bin2hex(random_bytes($length)), 0, $length);
            });
        }

        return $response;
    }));

    completeGoogleOAuth(beginGoogleOAuth())->assertOk()->assertJsonPath('data.username', 'google_'.$available);

    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('oauth_access_tokens', 1);
});

it('keeps remember me false across the callback refresh and logout flow', function (): void {
    $this->createPassportClient();
    fakeGoogleOAuth(googleOAuthProfile());
    $response = completeGoogleOAuth(beginGoogleOAuth(['remember_me' => 0]))->assertOk();
    $access = $response->getCookie('auth_token', false);
    $refresh = $response->getCookie('refresh_token', false);
    expect($access->getExpiresTime())->toBe(0);
    expect($refresh->getExpiresTime())->toBe(0);
    expect($access->isHttpOnly())->toBeTrue();
    expect($access->getSameSite())->toBe('strict');
    $this->assertDatabaseHas('refresh_tokens', ['remember_me' => false, 'revoked_at' => null]);
    $this->forgetAuthenticatedUser();
    $rotated = $this->withUnencryptedCookie('refresh_token', $refresh->getValue())->postJson(route('auth.refresh'))
        ->assertOk()->assertPlainCookie('auth_token');
    expect($rotated->getCookie('auth_token', false)->getExpiresTime())->toBe(0);
    $this->forgetAuthenticatedUser();

    $this->withUnencryptedCookie('auth_token', $rotated->getCookie('auth_token', false)->getValue())
        ->postJson(route('auth.logout'))->assertOk()->assertCookieExpired('auth_token');

    $this->assertDatabaseMissing('oauth_access_tokens', ['revoked' => false]);
    $this->assertDatabaseMissing('refresh_tokens', ['revoked_at' => null]);
});

it('validates remember me before starting Google authorization', function (): void {
    $this->getJson(route('auth.google.redirect', ['remember_me' => 'forever']))
        ->assertUnprocessable()->assertJsonValidationErrors('remember_me');

    $this->assertDatabaseCount('users', 0);
});

it('shares the guest API request allowance with Google authorization', function (): void {
    $this->freezeTime();
    for ($request = 0; $request < 30; $request++) {
        $this->getJson(route('plan.index'))->assertOk();
    }

    $this->getJson(route('auth.google.redirect'))->assertTooManyRequests()->assertHeader('Retry-After', '60');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('sessions', 0);
});

it('redirects success only to the configured frontend URI with cookies', function (): void {
    $this->createPassportClient();
    config(['services.google.frontend_redirect' => 'https://frontend.example/auth/google?from=login#complete']);
    fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth(['redirect_uri' => 'https://untrusted.example']);

    completeGoogleOAuth($flow, ['redirect_uri' => 'https://untrusted.example'])
        ->assertRedirect('https://frontend.example/auth/google?from=login&google=success#complete')
        ->assertPlainCookie('auth_token')->assertPlainCookie('refresh_token');

    $this->assertDatabaseCount('users', 1);
});

it('redirects link success with the linked result', function (): void {
    $this->createPassportClient();
    config(['services.google.frontend_redirect' => 'https://frontend.example/auth/google']);
    $user = User::factory()->create(['email' => 'ada@gmail.com']);
    fakeGoogleOAuth(googleOAuthProfile());

    completeGoogleOAuth(beginGoogleOAuthLink($user))
        ->assertRedirect('https://frontend.example/auth/google?google=linked')->assertPlainCookie('auth_token');

    expect($user->fresh()->google_id)->toBe('google-account-123');
});

it('redirects callback errors with an error code and no credentials', function (): void {
    config(['services.google.frontend_redirect' => 'https://frontend.example/auth/google']);
    $flow = beginGoogleOAuth();

    completeGoogleOAuth($flow, ['error' => 'access_denied', 'error_description' => 'private-data'])
        ->assertRedirect('https://frontend.example/auth/google?google=error&code=GOOGLE_OAUTH_CANCELLED')
        ->assertCookieMissing('auth_token');

    $this->assertDatabaseCount('users', 0);
});

it('returns 503 for missing or unsafe Google configuration', function (string $key, mixed $value): void {
    config(['services.google.'.$key => $value]);

    $this->getJson(route('auth.google.redirect'))->assertServiceUnavailable()->assertJsonPath('code', 'GOOGLE_AUTH_UNAVAILABLE')
        ->assertDontSee('test-google-secret')->assertCookieMissing('auth_token');

    $this->assertDatabaseCount('users', 0);
})->with([
    'missing client ID' => ['client_id', null],
    'missing secret' => ['client_secret', ''],
    'missing callback' => ['redirect', null],
    'unsafe callback scheme' => ['redirect', 'javascript:alert(1)'],
    'initiation URL instead of callback' => ['redirect', 'http://localhost:3010/api/v1/auth/google/redirect?remember_me=0'],
    'frontend page instead of callback' => ['redirect', 'http://localhost:3010/login'],
    'callback with query parameters' => ['redirect', 'http://localhost:3010/api/v1/auth/google/callback?remember_me=0'],
    'callback with fragment' => ['redirect', 'http://localhost:3010/api/v1/auth/google/callback#complete'],
    'relative frontend URI' => ['frontend_redirect', '/dashboard'],
    'frontend with credentials' => ['frontend_redirect', 'https://secret@frontend.example/auth'],
]);

it('uses Secure OAuth and authentication cookies in production', function (): void {
    $this->app['env'] = 'production';
    config(['services.google.redirect' => 'https://api.example/api/v1/auth/google/callback', 'session.secure' => false]);
    $this->createPassportClient();
    fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth();

    $response = completeGoogleOAuth($flow)->assertOk();

    expect($flow['response']->getCookie(config('session.cookie'), false)->isSecure())->toBeTrue();
    expect($response->getCookie('auth_token', false)->isSecure())->toBeTrue();
    expect(config('session.secure'))->toBeFalse();
});

it('returns 503 without exposing provider errors in responses or logs', function (): void {
    config(['app.debug' => true]);
    Log::spy();
    $handler = new MockHandler([
        new ConnectException('provider-secret google-authorization-code', new ProviderRequest('POST', 'https://www.googleapis.com/oauth2/v4/token')),
    ]);
    config(['services.google.guzzle.handler' => HandlerStack::create($handler)]);
    $flow = beginGoogleOAuth();

    completeGoogleOAuth($flow)->assertServiceUnavailable()->assertJsonPath('code', 'GOOGLE_AUTH_UNAVAILABLE')
        ->assertDontSee('provider-secret')->assertDontSee('google-authorization-code')->assertJsonMissingPath('debug');

    Log::shouldHaveReceived('warning')->once()->with('Unable to retrieve the Google sign-in profile.', ['exception' => ConnectException::class]);
    completeGoogleOAuth($flow)->assertBadRequest()->assertJsonPath('code', 'GOOGLE_OAUTH_INVALID_STATE');
    $this->assertDatabaseCount('users', 0);
});

it('rolls back Google registration if Passport cannot issue a token', function (): void {
    fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuth();

    completeGoogleOAuth($flow)->assertServiceUnavailable()->assertJsonPath('code', 'GOOGLE_AUTH_UNAVAILABLE');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('oauth_access_tokens', 0);
    $this->assertDatabaseCount('refresh_tokens', 0);
});

it('rolls back account linking if token signing fails', function (): void {
    $this->createPassportClient();
    $user = User::factory()->create(['email' => 'ada@gmail.com']);
    fakeGoogleOAuth(googleOAuthProfile());
    $flow = beginGoogleOAuthLink($user);
    config(['passport.private_key' => 'invalid-key']);

    completeGoogleOAuth($flow)->assertServiceUnavailable()->assertJsonPath('code', 'GOOGLE_AUTH_UNAVAILABLE');

    expect($user->fresh()->google_id)->toBeNull();
    $this->assertDatabaseCount('oauth_access_tokens', 1);
    $this->assertDatabaseCount('refresh_tokens', 1);
});

it('allows a Google registered user to establish a password through existing recovery', function (): void {
    $this->createPassportClient();
    fakeGoogleOAuth(googleOAuthProfile());
    completeGoogleOAuth(beginGoogleOAuth())->assertOk();
    $user = User::where('email', 'ada@gmail.com')->sole();
    Notification::fake([AuthOtpNotification::class]);
    $this->forgetAuthenticatedUser();

    $token = $this->requestResetToken($user);
    $this->postJson(route('auth.reset-password'), $this->resetPayload($user, $token))->assertOk();
    $this->postJson(route('auth.login'), ['username' => $user->username, 'password' => 'new secure password'])
        ->assertOk()->assertPlainCookie('auth_token');

    Notification::assertSentTo($user, AuthOtpNotification::class, fn (AuthOtpNotification $notification): bool => $notification->purpose === AuthOtpPurpose::PASSWORD_RESET);
    expect($user->fresh()->google_id)->toBe('google-account-123');
});
