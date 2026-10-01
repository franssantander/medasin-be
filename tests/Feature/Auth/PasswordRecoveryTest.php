<?php

namespace Tests\Feature\Auth;

use App\Enum\AuthOtpPurpose;
use App\Models\AuthOtp;
use App\Models\RefreshToken;
use App\Models\User;
use App\Notifications\AuthOtpNotification;
use App\Services\Auth\TokenService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\RefreshToken as PassportRefreshToken;
use PHPUnit\Framework\Attributes\DataProvider;

class PasswordRecoveryTest extends AuthTestCase
{
    public function test_forgot_password_hides_unknown_accounts_and_cooldown_with_identical_responses(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        Notification::fake();

        $sent = $this->postJson(route('auth.forgot-password'), ['email' => $user->email])
            ->assertAccepted()->assertCookieMissing('auth_token');
        $cooldown = $this->postJson(route('auth.forgot-password'), ['email' => $user->email])
            ->assertAccepted();
        $unknown = $this->postJson(route('auth.forgot-password'), ['email' => 'unknown@example.com'])
            ->assertAccepted();

        $this->assertSame($sent->json(), $cooldown->json());
        $this->assertSame($sent->json(), $unknown->json());
        Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
        $notification = $this->latestOtp($user, AuthOtpPurpose::PASSWORD_RESET);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $notification->code);
        $challenge = AuthOtp::where('user_id', $user->id)->sole();
        $this->assertTrue(Hash::check($notification->code, $challenge->code_hash));
        $this->assertTrue($challenge->expires_at->equalTo(now()->addMinutes(10)));
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_verifies_recovery_otp_once_and_returns_a_hashed_fifteen_minute_reset_grant(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson(route('auth.forgot-password'), ['email' => $user->email])->assertAccepted();
        $payload = [
            'email' => $user->email,
            'otp' => $this->latestOtp($user, AuthOtpPurpose::PASSWORD_RESET)->code,
        ];

        $response = $this->postJson(route('auth.verify-password-reset'), $payload);

        $response->assertOk()->assertJsonPath('data.expires_in', 900)
            ->assertCookieMissing('auth_token')->assertCookieMissing('refresh_token');
        $token = $response->json('data.reset_token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->sole();
        $this->assertTrue(Hash::check($token, $stored->token));
        $this->assertNotNull(AuthOtp::where('user_id', $user->id)->sole()->consumed_at);
        $this->postJson(route('auth.verify-password-reset'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_resets_password_revokes_all_existing_sessions_and_requires_a_new_login(): void
    {
        $this->createPassportClient();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $rememberToken = $user->remember_token;
        $tokens = app(TokenService::class);
        [$oldAccess, $oldRefresh] = $tokens->issue($user);
        $tokens->issue($user);
        $tokens->issue($other);
        $accessTokenIds = $user->tokens()->pluck('id');
        $nativeRefresh = PassportRefreshToken::forceCreate([
            'id' => str_repeat('r', 80),
            'access_token_id' => $accessTokenIds->first(),
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);
        Notification::fake();
        $resetToken = $this->requestResetToken($user);
        Event::fake([PasswordReset::class]);

        $response = $this->postJson(route('auth.reset-password'), $this->resetPayload($user, $resetToken));

        $response->assertOk()->assertCookieExpired('auth_token')->assertCookieExpired('refresh_token');
        $updated = $user->fresh();
        $this->assertTrue(Hash::check('new secure password', $updated->password));
        $this->assertNotSame($rememberToken, $updated->remember_token);
        $this->assertTrue($updated->hasVerifiedEmail());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertSame(0, $user->tokens()->where('revoked', false)->count());
        $this->assertTrue($nativeRefresh->fresh()->revoked);
        $this->assertSame(0, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->assertSame(1, $other->tokens()->where('revoked', false)->count());
        $this->assertSame(1, RefreshToken::where('user_id', $other->id)->whereNull('revoked_at')->count());
        Event::assertDispatched(
            PasswordReset::class,
            fn (PasswordReset $event): bool => $event->user->id === $user->id,
        );

        $this->forgetAuthenticatedUser();
        $this->withUnencryptedCookie('auth_token', $oldAccess->getValue())
            ->getJson(route('auth.me'))->assertUnauthorized();
        $this->forgetAuthenticatedUser();
        $this->withUnencryptedCookie('refresh_token', $oldRefresh->getValue())
            ->postJson(route('auth.refresh'))->assertUnauthorized();
        $this->forgetAuthenticatedUser();
        $this->postJson(route('auth.login'), ['username' => $user->username, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->forgetAuthenticatedUser();
        $this->postJson(route('auth.login'), [
            'username' => $user->username,
            'password' => 'new secure password',
        ])->assertOk()->assertPlainCookie('auth_token');
    }

    public function test_returns_422_for_consumed_reset_token_without_changing_password_again(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $token = $this->requestResetToken($user);
        $this->postJson(route('auth.reset-password'), $this->resetPayload($user, $token))->assertOk();

        $this->postJson(route('auth.reset-password'), array_replace($this->resetPayload($user, $token), [
            'password' => 'a different password',
            'password_confirmation' => 'a different password',
        ]))->assertUnprocessable()->assertJsonValidationErrors('reset_token');

        $this->assertTrue(Hash::check('new secure password', $user->fresh()->password));
    }

    public function test_returns_422_for_expired_reset_token_without_changing_password_or_sessions(): void
    {
        $this->freezeTime();
        $this->createPassportClient();
        $user = User::factory()->create();
        app(TokenService::class)->issue($user);
        $password = $user->password;
        Notification::fake();
        $token = $this->requestResetToken($user);
        $this->travel(901)->seconds();
        Event::fake([PasswordReset::class]);

        $this->postJson(route('auth.reset-password'), $this->resetPayload($user, $token))
            ->assertUnprocessable()->assertJsonValidationErrors('reset_token');

        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame(1, $user->tokens()->where('revoked', false)->count());
        $this->assertSame(1, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
        Event::assertNotDispatched(PasswordReset::class);
    }

    public function test_returns_422_when_a_reset_grant_is_used_for_another_email(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $otherPassword = $other->password;
        $token = $this->requestResetToken($owner);

        $this->postJson(route('auth.reset-password'), $this->resetPayload($other, $token))
            ->assertUnprocessable()->assertJsonValidationErrors('reset_token');

        $this->assertSame($otherPassword, $other->fresh()->password);
        $this->postJson(route('auth.reset-password'), $this->resetPayload($owner, $token))->assertOk();
    }

    public function test_new_recovery_otp_invalidates_the_previous_reset_grant_and_otp(): void
    {
        $this->freezeTime();
        Notification::fake();
        $user = User::factory()->create();
        $token = $this->requestResetToken($user);
        $previous = $this->latestOtp($user, AuthOtpPurpose::PASSWORD_RESET);
        $this->travel(60)->seconds();

        $this->postJson(route('auth.forgot-password'), ['email' => $user->email])->assertAccepted();

        $replacement = $this->latestOtp($user, AuthOtpPurpose::PASSWORD_RESET);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertNotSame($previous->version, $replacement->version);
        $this->postJson(route('auth.reset-password'), $this->resetPayload($user, $token))
            ->assertUnprocessable()->assertJsonValidationErrors('reset_token');
        $this->postJson(route('auth.verify-password-reset'), ['email' => $user->email, 'otp' => $previous->code])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->postJson(route('auth.verify-password-reset'), [
            'email' => $user->email,
            'otp' => $replacement->code,
        ])->assertOk();
    }

    public function test_suppressed_recovery_send_during_cooldown_keeps_the_existing_grant_valid(): void
    {
        $this->freezeTime();
        Notification::fake();
        $user = User::factory()->create();
        $token = $this->requestResetToken($user);

        $this->postJson(route('auth.forgot-password'), ['email' => $user->email])->assertAccepted();
        $this->postJson(route('auth.reset-password'), $this->resetPayload($user, $token))->assertOk();

        Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
    }

    public function test_resetting_password_does_not_verify_an_unverified_user_or_sign_them_in(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = $this->requestResetToken($user);

        $this->postJson(route('auth.reset-password'), $this->resetPayload($user, $token))
            ->assertOk()->assertCookieExpired('auth_token')->assertCookieExpired('refresh_token');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertTrue(Hash::check('new secure password', $user->fresh()->password));
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->postJson(route('auth.login'), [
            'username' => $user->username,
            'password' => 'new secure password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_returns_422_at_recovery_otp_expiry_without_creating_a_reset_grant(): void
    {
        $this->freezeTime();
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson(route('auth.forgot-password'), ['email' => $user->email])->assertAccepted();
        $code = $this->latestOtp($user, AuthOtpPurpose::PASSWORD_RESET)->code;
        $this->travel(10)->minutes();

        $this->postJson(route('auth.verify-password-reset'), ['email' => $user->email, 'otp' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_returns_422_after_five_incorrect_recovery_codes_without_creating_a_grant(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson(route('auth.forgot-password'), ['email' => $user->email])->assertAccepted();
        $code = $this->latestOtp($user, AuthOtpPurpose::PASSWORD_RESET)->code;
        $wrongCode = $code === '000000' ? '000001' : '000000';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('auth.verify-password-reset'), ['email' => $user->email, 'otp' => $wrongCode])
                ->assertUnprocessable()->assertJsonValidationErrors('otp');
        }
        $this->postJson(route('auth.verify-password-reset'), ['email' => $user->email, 'otp' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');

        $this->assertSame(5, AuthOtp::where('user_id', $user->id)->sole()->failed_attempts);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_returns_422_for_unknown_recovery_email_with_the_same_proof_error(): void
    {
        $user = User::factory()->create();

        $known = $this->postJson(route('auth.verify-password-reset'), ['email' => $user->email, 'otp' => '123456'])
            ->assertUnprocessable();
        $unknown = $this->postJson(route('auth.verify-password-reset'), [
            'email' => 'unknown@example.com',
            'otp' => '123456',
        ])->assertUnprocessable();

        $this->assertSame($known->json(), $unknown->json());
    }

    #[DataProvider('invalidResetPasswords')]
    public function test_returns_422_for_invalid_new_password_without_consuming_the_reset_grant(
        string $password,
        ?string $confirmation,
    ): void {
        Notification::fake();
        $user = User::factory()->create();
        $oldPassword = $user->password;
        $token = $this->requestResetToken($user);

        $this->postJson(route('auth.reset-password'), array_replace($this->resetPayload($user, $token), [
            'password' => $password,
            'password_confirmation' => $confirmation,
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertSame($oldPassword, $user->fresh()->password);
        $this->postJson(route('auth.reset-password'), $this->resetPayload($user, $token))->assertOk();
    }

    /** @return array<string, array{string, ?string}> */
    public static function invalidResetPasswords(): array
    {
        return [
            'short password' => ['1234567', '1234567'],
            'missing confirmation' => ['new secure password', null],
            'mismatched confirmation' => ['new secure password', 'other password'],
            'above bcrypt byte limit' => [str_repeat('a', 73), str_repeat('a', 73)],
            'password with null byte' => ["safe\0password", "safe\0password"],
        ];
    }

    public function test_returns_422_for_missing_reset_fields(): void
    {
        $this->postJson(route('auth.reset-password'), [])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'reset_token', 'password']);
    }

    #[DataProvider('emailOnlyEndpoints')]
    public function test_returns_422_for_invalid_email_in_send_endpoints(string $routeName): void
    {
        Notification::fake();

        $this->postJson(route($routeName), ['email' => 'invalid-address'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        Notification::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function emailOnlyEndpoints(): array
    {
        return [
            'password recovery' => ['auth.forgot-password'],
            'verification resend' => ['auth.resend-verification'],
        ];
    }
}
