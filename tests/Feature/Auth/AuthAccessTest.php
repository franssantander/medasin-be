<?php

namespace Tests\Feature\Auth;

use App\Models\AuthOtp;
use App\Models\RefreshToken;
use App\Models\User;
use App\Notifications\AuthOtpNotification;
use App\Services\Auth\TokenService;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthAccessTest extends AuthTestCase
{
    public function test_returns_401_for_profile_requests_without_authentication(): void
    {
        $this->getJson(route('auth.me'))->assertUnauthorized();
    }

    public function test_returns_422_for_unverified_login_without_issuing_tokens(): void
    {
        $user = User::factory()->unverified()->create();
        Notification::fake();

        $this->postJson(route('auth.login'), ['username' => $user->username, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')
            ->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.verification_required', true)
            ->assertJsonPath('data.otp_expires_in', 0)
            ->assertJsonPath('data.resend_after', 0)
            ->assertJsonPath('errors.email.0', 'Please verify your email address before logging in.')
            ->assertCookieMissing('auth_token')->assertCookieMissing('refresh_token');

        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
        $this->assertDatabaseCount('auth_otps', 0);
        Notification::assertNothingSent();
    }

    public function test_unverified_login_preserves_the_existing_code_and_returns_remaining_time(): void
    {
        $this->freezeSecond();
        Notification::fake();
        $this->postJson(route('auth.register'), $this->registrationPayload())->assertCreated();
        $user = User::where('email', 'ada@example.com')->sole();
        $challenge = AuthOtp::where('user_id', $user->id)->sole();
        $this->travel(30)->seconds();

        $this->postJson(route('auth.login'), [
            'username' => $user->username,
            'password' => 'a secure password',
        ])->assertUnprocessable()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.otp_expires_in', 3570)->assertJsonPath('data.resend_after', 30)
            ->assertCookieMissing('auth_token')->assertCookieMissing('refresh_token');
        $this->travel(60)->seconds();
        $this->postJson(route('auth.login'), [
            'username' => $user->username,
            'password' => 'a secure password',
        ])->assertUnprocessable()->assertJsonPath('data.otp_expires_in', 3510)
            ->assertJsonPath('data.resend_after', 0);

        $this->assertSame($challenge->version, $challenge->fresh()->version);
        $this->assertSame($challenge->code_hash, $challenge->fresh()->code_hash);
        $this->assertDatabaseCount('auth_otps', 1);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
        Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
    }

    public function test_unverified_login_returns_an_expired_challenge_without_resending_or_issuing_cookies(): void
    {
        $this->freezeSecond();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        $challenge = AuthOtp::where('user_id', $user->id)->sole();
        $this->travel(60)->minutes();

        $this->postJson(route('auth.login'), ['username' => $user->username, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED')
            ->assertJsonPath('data.otp_expires_in', 0)->assertJsonPath('data.resend_after', 0)
            ->assertCookieMissing('auth_token')->assertCookieMissing('refresh_token');

        $this->assertSame($challenge->version, $challenge->fresh()->version);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
        Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
    }

    #[DataProvider('inactiveVerificationChallenges')]
    public function test_unverified_login_marks_consumed_or_exhausted_codes_unavailable_and_preserves_cooldown(array $changes): void
    {
        $this->travelTo('2026-10-01T00:00:00+00:00');
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        AuthOtp::where('user_id', $user->id)->update($changes);
        $this->travel(30)->seconds();

        $this->postJson(route('auth.login'), ['username' => $user->username, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonPath('data.otp_expires_in', 0)
            ->assertJsonPath('data.resend_after', 30)->assertCookieMissing('auth_token');

        $this->assertDatabaseCount('oauth_access_tokens', 0);
        Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
    }

    /** @return array<string, array{array<string, int|string>}> */
    public static function inactiveVerificationChallenges(): array
    {
        return [
            'consumed' => [['consumed_at' => '2026-10-01 00:00:00']],
            'exhausted' => [['failed_attempts' => 5]],
        ];
    }

    public function test_returns_422_for_wrong_password_without_disclosing_verification_email_or_sending_a_code(): void
    {
        $user = User::factory()->unverified()->create();
        Notification::fake();

        $known = $this->postJson(route('auth.login'), [
            'username' => $user->username,
            'password' => 'incorrect password',
        ])->assertUnprocessable()->assertJsonValidationErrors('username')
            ->assertJsonPath('data', null)->assertJsonMissingPath('code')
            ->assertCookieMissing('auth_token')->assertCookieMissing('refresh_token');
        $unknown = $this->postJson(route('auth.login'), [
            'username' => 'unknown-username',
            'password' => 'incorrect password',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');

        $this->assertSame($known->json(), $unknown->json());
        $this->assertStringNotContainsString($user->email, $known->getContent());
        $this->assertDatabaseCount('auth_otps', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_verified_login_authenticates_subsequent_cookie_requests(): void
    {
        $this->createPassportClient();
        $user = User::factory()->create();

        $response = $this->postJson(route('auth.login'), [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk()->assertPlainCookie('auth_token')->assertPlainCookie('refresh_token');

        $this->assertSame(1, $user->tokens()->where('revoked', false)->count());
        $this->assertSame(1, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->forgetAuthenticatedUser();
        $this->withUnencryptedCookie('auth_token', $response->getCookie('auth_token', false)->getValue())
            ->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.id', $user->id);
    }

    #[DataProvider('rememberMePreferences')]
    public function test_login_uses_the_requested_cookie_persistence_or_the_legacy_default(
        array $preference,
        bool $rememberMe,
        int $accessLifetime,
        int $refreshLifetime,
    ): void {
        $this->freezeSecond();
        $this->createPassportClient();
        $user = User::factory()->create();

        $response = $this->postJson(route('auth.login'), [
            'username' => $user->username,
            'password' => 'password',
            ...$preference,
        ])->assertOk()->assertPlainCookie('auth_token')->assertPlainCookie('refresh_token');

        $access = $response->getCookie('auth_token', false);
        $refresh = $response->getCookie('refresh_token', false);
        $this->assertSame($accessLifetime ? now()->timestamp + $accessLifetime : 0, $access->getExpiresTime());
        $this->assertSame($refreshLifetime ? now()->timestamp + $refreshLifetime : 0, $refresh->getExpiresTime());
        $this->assertTrue($access->isHttpOnly());
        $this->assertTrue($refresh->isHttpOnly());
        $this->assertSame('/', $access->getPath());
        $this->assertSame('/api/v1/auth', $refresh->getPath());
        $this->assertSame('strict', $access->getSameSite());
        $this->assertSame('strict', $refresh->getSameSite());
        $stored = RefreshToken::where('user_id', $user->id)->sole();
        $this->assertSame($rememberMe, $stored->remember_me);
        $this->assertTrue($stored->expires_at->equalTo(now()->addDays(30)));
        $this->assertDatabaseCount('oauth_access_tokens', 1);
    }

    /** @return array<string, array{array<string, bool>, bool, int, int}> */
    public static function rememberMePreferences(): array
    {
        return [
            'legacy default' => [[], true, 604800, 2592000],
            'browser session' => [['remember_me' => false], false, 0, 0],
            'remembered' => [['remember_me' => true], true, 604800, 2592000],
        ];
    }

    #[DataProvider('invalidRememberMeValues')]
    public function test_returns_422_for_invalid_login_remember_me_without_issuing_tokens(mixed $preference): void
    {
        $user = User::factory()->create();

        $this->postJson(route('auth.login'), [
            'username' => $user->username,
            'password' => 'password',
            'remember_me' => $preference,
        ])->assertUnprocessable()->assertJsonValidationErrors('remember_me')
            ->assertCookieMissing('auth_token')->assertCookieMissing('refresh_token');

        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidRememberMeValues(): array
    {
        return [
            'null' => [null],
            'text' => ['yes'],
            'boolean text' => ['false'],
            'array' => [[true]],
            'invalid number' => [2],
        ];
    }

    #[DataProvider('rotationPreferences')]
    public function test_refresh_preserves_saved_persistence_and_ignores_the_request_preference(
        bool $rememberMe,
        int $accessLifetime,
        int $refreshLifetime,
    ): void {
        $this->freezeSecond();
        $this->createPassportClient();
        $user = User::factory()->create();
        [, $originalCookie] = app(TokenService::class)->issue($user, $rememberMe);
        $original = RefreshToken::where('user_id', $user->id)->sole();
        $this->travel(1)->days();

        $response = $this->withUnencryptedCookie('refresh_token', $originalCookie->getValue())
            ->postJson(route('auth.refresh'), ['remember_me' => ! $rememberMe])
            ->assertOk()->assertPlainCookie('auth_token')->assertPlainCookie('refresh_token');

        $this->assertSame($accessLifetime ? now()->timestamp + $accessLifetime : 0, $response->getCookie('auth_token', false)->getExpiresTime());
        $this->assertSame($refreshLifetime ? now()->timestamp + $refreshLifetime : 0, $response->getCookie('refresh_token', false)->getExpiresTime());
        $current = RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->sole();
        $this->assertSame($rememberMe, $current->remember_me);
        $this->assertTrue($current->expires_at->equalTo(now()->addDays(30)));
        $this->assertNotNull($original->fresh()->revoked_at);
        $this->assertSame(1, $user->tokens()->where('revoked', false)->count());
        $this->assertSame(1, $user->tokens()->where('revoked', true)->count());
    }

    /** @return array<string, array{bool, int, int}> */
    public static function rotationPreferences(): array
    {
        return [
            'session remains session' => [false, 0, 0],
            'remembered remains remembered' => [true, 604800, 2592000],
        ];
    }

    public function test_refresh_tokens_without_a_persistence_preference_default_to_persistent_cookies(): void
    {
        $this->freezeSecond();
        $this->createPassportClient();
        $user = User::factory()->create();
        DB::table('refresh_tokens')->insert([
            'user_id' => $user->id,
            'access_token_id' => null,
            'token' => hash('sha256', 'legacy-refresh-token'),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $original = RefreshToken::where('user_id', $user->id)->sole();
        $this->assertTrue($original->remember_me);
        $response = $this->withUnencryptedCookie('refresh_token', 'legacy-refresh-token')
            ->postJson(route('auth.refresh'))->assertOk()->assertPlainCookie('refresh_token');
        $this->assertSame(now()->timestamp + 2592000, $response->getCookie('refresh_token', false)->getExpiresTime());
        $this->assertTrue(RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->sole()->remember_me);
        $this->assertNotNull($original->fresh()->revoked_at);
    }

    #[DataProvider('protectedEndpoints')]
    public function test_returns_json_403_for_unverified_users_across_protected_domains_without_accept_header(
        string $routeName,
    ): void {
        Passport::actingAs(User::factory()->unverified()->create());

        $this->get(route($routeName))->assertForbidden()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('status', 403)
            ->assertJsonPath('message', 'Please verify your email address before accessing the app.');
    }

    /** @return array<string, array{string}> */
    public static function protectedEndpoints(): array
    {
        return [
            'profile' => ['auth.me'],
            'home' => ['home.show'],
            'calendar' => ['calendar.plans.index'],
            'notifications' => ['notifications.index'],
            'areas' => ['area.index'],
            'notes' => ['notes.index'],
            'projects' => ['project.index'],
            'boards' => ['board.index'],
            'resources' => ['resource.index'],
            'trash' => ['trash.index'],
            'focus' => ['focus.show'],
            'habits' => ['habits.index'],
            'journal' => ['journal.index'],
            'letters' => ['letters.index'],
            'search' => ['search.index'],
        ];
    }

    public function test_returns_json_403_for_unverified_broadcast_authorization(): void
    {
        Passport::actingAs(User::factory()->unverified()->create());

        $this->post('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-users.1.notifications',
        ])->assertForbidden()->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('status', 403);
    }

    public function test_public_plan_routes_remain_accessible_to_unverified_authenticated_users(): void
    {
        Passport::actingAs(User::factory()->unverified()->create());

        $this->getJson(route('plan.index'))->assertOk();
    }

    public function test_unverified_users_can_logout_and_revoke_their_existing_session(): void
    {
        $this->createPassportClient();
        $user = User::factory()->unverified()->create();
        $access = $user->createToken('existing session');
        $refresh = RefreshToken::create([
            'user_id' => $user->id,
            'access_token_id' => $access->token->id,
            'token' => hash('sha256', 'existing-refresh-token'),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($access->accessToken)->postJson(route('auth.logout'))
            ->assertOk()->assertCookieExpired('auth_token')->assertCookieExpired('refresh_token');

        $this->assertTrue($access->token->fresh()->revoked);
        $this->assertNotNull($refresh->fresh()->revoked_at);
    }

    public function test_refresh_rejects_an_unverified_user_without_creating_a_new_session(): void
    {
        $this->createPassportClient();
        $user = User::factory()->create();
        [, $refresh] = app(TokenService::class)->issue($user);
        $user->forceFill(['email_verified_at' => null])->save();

        $this->withUnencryptedCookie('refresh_token', $refresh->getValue())
            ->postJson(route('auth.refresh'))->assertUnauthorized()
            ->assertCookieMissing('auth_token')->assertCookieMissing('refresh_token');

        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('refresh_tokens', 1);
    }

    public function test_refresh_rotates_a_valid_cookie_after_the_access_token_has_expired(): void
    {
        $this->createPassportClient();
        $user = User::factory()->create();
        $originalLifetime = Passport::personalAccessTokensExpireIn();
        $expiredLifetime = new DateInterval('PT1M');
        $expiredLifetime->invert = 1;

        try {
            Passport::personalAccessTokensExpireIn($expiredLifetime);
            [$expiredAccess, $refresh] = app(TokenService::class)->issue($user);
        } finally {
            Passport::personalAccessTokensExpireIn($originalLifetime);
        }

        $this->withUnencryptedCookie('auth_token', $expiredAccess->getValue())
            ->getJson(route('auth.me'))->assertUnauthorized();
        $this->forgetAuthenticatedUser();

        $response = $this->withUnencryptedCookie('refresh_token', $refresh->getValue())
            ->postJson(route('auth.refresh'))->assertOk()
            ->assertPlainCookie('auth_token')->assertPlainCookie('refresh_token');

        $this->assertSame(1, $user->tokens()->where('revoked', true)->count());
        $this->assertSame(1, $user->tokens()->where('revoked', false)->count());
        $this->assertSame(1, RefreshToken::where('user_id', $user->id)->whereNotNull('revoked_at')->count());
        $this->assertSame(1, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->forgetAuthenticatedUser();
        $this->withUnencryptedCookie('auth_token', $response->getCookie('auth_token', false)->getValue())
            ->getJson(route('auth.me'))->assertOk();

        $this->forgetAuthenticatedUser();
        $this->withUnencryptedCookie('refresh_token', $refresh->getValue())
            ->postJson(route('auth.refresh'))->assertUnauthorized();
    }

    #[DataProvider('invalidRefreshCookies')]
    public function test_returns_401_for_missing_or_invalid_refresh_cookie(?string $cookie): void
    {
        if ($cookie !== null) {
            $this->withUnencryptedCookie('refresh_token', $cookie);
        }

        $this->postJson(route('auth.refresh'))->assertUnauthorized()
            ->assertCookieMissing('auth_token')->assertCookieMissing('refresh_token');
    }

    /** @return array<string, array{?string}> */
    public static function invalidRefreshCookies(): array
    {
        return [
            'missing' => [null],
            'invalid' => ['unknown-refresh-token'],
        ];
    }

    public function test_returns_401_for_expired_custom_refresh_cookie(): void
    {
        $this->freezeTime();
        $this->createPassportClient();
        $user = User::factory()->create();
        [, $refresh] = app(TokenService::class)->issue($user);
        $this->travel(31)->days();

        $this->withUnencryptedCookie('refresh_token', $refresh->getValue())
            ->postJson(route('auth.refresh'))->assertUnauthorized();

        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('refresh_tokens', 1);
    }
}
