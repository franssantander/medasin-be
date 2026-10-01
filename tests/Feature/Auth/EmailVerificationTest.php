<?php

namespace Tests\Feature\Auth;

use App\Enum\AuthOtpPurpose;
use App\Models\AuthOtp;
use App\Models\User;
use App\Notifications\AuthOtpNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;

class EmailVerificationTest extends AuthTestCase
{
    public function test_consumes_email_otp_verifies_the_user_and_signs_in_with_existing_cookies(): void
    {
        $this->freezeTime();
        $this->createPassportClient();
        Notification::fake();
        $this->postJson(route('auth.register'), $this->registrationPayload())->assertCreated();
        $user = User::where('email', 'ada@example.com')->sole();
        $notification = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
        Event::fake([Verified::class]);

        $response = $this->postJson(route('auth.verify-email'), [
            'email' => $user->email,
            'otp' => $notification->code,
        ]);

        $response->assertOk()->assertJsonPath('data.email', $user->email)
            ->assertPlainCookie('auth_token')->assertPlainCookie('refresh_token');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $challenge = AuthOtp::where('user_id', $user->id)->sole();
        $this->assertNotNull($challenge->consumed_at);
        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('refresh_tokens', 1);
        Event::assertDispatched(
            Verified::class,
            fn (Verified $event): bool => $event->user->id === $user->id,
        );
        $accessCookie = $response->getCookie('auth_token', false);
        $refreshCookie = $response->getCookie('refresh_token', false);
        $this->assertTrue($accessCookie->isHttpOnly());
        $this->assertSame('/', $accessCookie->getPath());
        $this->assertSame('strict', $accessCookie->getSameSite());
        $this->assertTrue($refreshCookie->isHttpOnly());
        $this->assertSame('/api/v1/auth', $refreshCookie->getPath());

        $this->forgetAuthenticatedUser();
        $this->withUnencryptedCookie('auth_token', $accessCookie->getValue())
            ->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_returns_422_for_replayed_email_otp_without_issuing_another_session(): void
    {
        $this->createPassportClient();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        $payload = [
            'email' => $user->email,
            'otp' => $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION)->code,
        ];
        $this->postJson(route('auth.verify-email'), $payload)->assertOk();

        $this->postJson(route('auth.verify-email'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('otp')
            ->assertCookieMissing('auth_token');

        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('refresh_tokens', 1);
    }

    public function test_returns_422_at_email_otp_expiry_without_verifying_or_issuing_tokens(): void
    {
        $this->freezeTime();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        $code = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION)->code;
        $this->travel(60)->minutes();
        Event::fake([Verified::class]);

        $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        Event::assertNotDispatched(Verified::class);
    }

    public function test_accepts_an_email_otp_just_before_the_sixty_minute_expiry(): void
    {
        $this->freezeSecond();
        $this->createPassportClient();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        $code = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION)->code;
        $this->travel(3599)->seconds();

        $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => $code])
            ->assertOk()->assertPlainCookie('auth_token')->assertPlainCookie('refresh_token');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('oauth_access_tokens', 1);
        Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
    }

    public function test_resends_and_verifies_a_new_email_code_after_sixty_minutes(): void
    {
        $this->freezeSecond();
        $this->createPassportClient();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        $old = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
        $this->travel(60)->minutes();
        $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => $old->code])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');

        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();

        $current = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
        $this->assertNotSame($old->version, $current->version);
        $this->assertTrue(AuthOtp::where('user_id', $user->id)->sole()->expires_at->equalTo(now()->addMinutes(60)));
        $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => $old->code])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => $current->code])
            ->assertOk()->assertPlainCookie('auth_token');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Notification::assertSentToTimes($user, AuthOtpNotification::class, 2);
    }

    public function test_uses_the_configured_email_verification_lifetime(): void
    {
        $this->freezeSecond();
        config(['auth.otp.email_verification.expire' => 20]);
        Notification::fake();

        $this->postJson(route('auth.register'), $this->registrationPayload())->assertCreated()
            ->assertJsonPath('data.otp_expires_in', 1200);

        $user = User::where('email', 'ada@example.com')->sole();
        $this->assertTrue(AuthOtp::where('user_id', $user->id)->sole()->expires_at->equalTo(now()->addMinutes(20)));
        $content = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION)->toMail($user)->render();
        $this->assertStringContainsString('20 minutes after it was requested', $content);
        Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
    }

    public function test_returns_422_after_five_wrong_codes_even_for_the_correct_code_from_another_ip(): void
    {
        $this->freezeTime();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        $code = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION)->code;
        $wrongCode = $code === '000000' ? '000001' : '000000';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('auth.verify-email'), [
                'email' => $user->email,
                'otp' => $wrongCode,
            ])->assertUnprocessable()->assertJsonValidationErrors('otp');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
            ->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');

        $this->assertSame(5, AuthOtp::where('user_id', $user->id)->sole()->failed_attempts);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_resending_after_cooldown_replaces_code_and_resets_failed_attempts(): void
    {
        $this->freezeTime();
        $this->createPassportClient();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        $oldNotification = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
        $wrongCode = $oldNotification->code === '000000' ? '000001' : '000000';
        $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => $wrongCode])
            ->assertUnprocessable();
        $this->travel(60)->seconds();

        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();

        $newNotification = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
        $challenge = AuthOtp::where('user_id', $user->id)->sole();
        $this->assertSame(0, $challenge->failed_attempts);
        $this->assertNotSame($oldNotification->version, $newNotification->version);
        $this->assertTrue(Hash::check($newNotification->code, $challenge->code_hash));
        $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => $oldNotification->code])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => $newNotification->code])
            ->assertOk();
    }

    public function test_resend_hides_unknown_verified_and_cooldown_accounts_with_identical_acknowledgements(): void
    {
        $this->freezeTime();
        $pending = User::factory()->unverified()->create();
        $verified = User::factory()->create();
        Notification::fake();

        $sent = $this->postJson(route('auth.resend-verification'), ['email' => $pending->email])
            ->assertAccepted();
        $cooldown = $this->postJson(route('auth.resend-verification'), ['email' => $pending->email])
            ->assertAccepted();
        $unknown = $this->postJson(route('auth.resend-verification'), ['email' => 'unknown@example.com'])
            ->assertAccepted();
        $alreadyVerified = $this->postJson(route('auth.resend-verification'), ['email' => $verified->email])
            ->assertAccepted();

        $this->assertSame($sent->json(), $cooldown->json());
        $this->assertSame($sent->json(), $unknown->json());
        $this->assertSame($sent->json(), $alreadyVerified->json());
        Notification::assertSentToTimes($pending, AuthOtpNotification::class, 1);
        Notification::assertNotSentTo($verified, AuthOtpNotification::class);
        $this->assertDatabaseCount('auth_otps', 1);
    }

    public function test_returns_422_for_unknown_email_without_disclosing_whether_an_account_exists(): void
    {
        $user = User::factory()->unverified()->create();

        $known = $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => '123456'])
            ->assertUnprocessable();
        $unknown = $this->postJson(route('auth.verify-email'), [
            'email' => 'unknown@example.com',
            'otp' => '123456',
        ])->assertUnprocessable();

        $this->assertSame($known->json(), $unknown->json());
    }

    #[DataProvider('invalidOtpPayloads')]
    public function test_returns_422_for_invalid_email_verification_input(array $payload, array $errors): void
    {
        $this->postJson(route('auth.verify-email'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function invalidOtpPayloads(): array
    {
        return [
            'missing fields' => [[], ['email', 'otp']],
            'invalid email' => [['email' => 'invalid', 'otp' => '123456'], ['email']],
            'short code' => [['email' => 'ada@example.com', 'otp' => '12345'], ['otp']],
            'long code' => [['email' => 'ada@example.com', 'otp' => '1234567'], ['otp']],
            'alphabetic code' => [['email' => 'ada@example.com', 'otp' => 'abcdef'], ['otp']],
            'numeric code type' => [['email' => 'ada@example.com', 'otp' => 123456], ['otp']],
        ];
    }

    public function test_accepts_a_leading_zero_otp_as_a_six_digit_string(): void
    {
        $this->createPassportClient();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        AuthOtp::where('user_id', $user->id)->update(['code_hash' => Hash::make('012345')]);

        $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => '012345'])
            ->assertOk()->assertPlainCookie('auth_token');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_keeps_verification_and_password_recovery_codes_separate(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        $verification = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
        $this->postJson(route('auth.forgot-password'), ['email' => $user->email])->assertAccepted();
        $recovery = $this->latestOtp($user, AuthOtpPurpose::PASSWORD_RESET);
        AuthOtp::where('user_id', $user->id)
            ->where('purpose', AuthOtpPurpose::EMAIL_VERIFICATION->value)
            ->update(['code_hash' => Hash::make('111111')]);
        AuthOtp::where('user_id', $user->id)
            ->where('purpose', AuthOtpPurpose::PASSWORD_RESET->value)
            ->update(['code_hash' => Hash::make('222222')]);

        $this->postJson(route('auth.verify-email'), ['email' => $user->email, 'otp' => '222222'])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->postJson(route('auth.verify-password-reset'), ['email' => $user->email, 'otp' => '111111'])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('auth_otps', 2);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame(AuthOtpPurpose::EMAIL_VERIFICATION, $verification->purpose);
        $this->assertSame(AuthOtpPurpose::PASSWORD_RESET, $recovery->purpose);
    }
}
