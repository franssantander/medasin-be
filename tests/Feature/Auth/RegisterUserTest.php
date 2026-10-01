<?php

namespace Tests\Feature\Auth;

use App\Enum\AuthOtpPurpose;
use App\Models\AuthOtp;
use App\Models\User;
use App\Notifications\AuthOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;

class RegisterUserTest extends AuthTestCase
{
    public function test_creates_an_unverified_user_and_sends_one_hashed_otp_without_auth_cookies(): void
    {
        $this->freezeSecond();
        Notification::fake();
        $payload = $this->registrationPayload();

        $response = $this->postJson(route('auth.register'), $payload);

        $response->assertCreated()
            ->assertJsonPath('data.email', $payload['email'])
            ->assertJsonPath('data.verification_required', true)
            ->assertJsonPath('data.otp_expires_in', 3600)
            ->assertJsonPath('data.resend_after', 60)
            ->assertCookieMissing('auth_token')
            ->assertCookieMissing('refresh_token');
        $user = User::where('email', $payload['email'])->sole();
        $this->assertSame($payload['first_name'], $user->first_name);
        $this->assertSame($payload['last_name'], $user->last_name);
        $this->assertSame($payload['username'], $user->username);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
        $notification = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
        Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
        $challenge = AuthOtp::where('user_id', $user->id)->sole();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $notification->code);
        $this->assertTrue(Hash::check($notification->code, $challenge->code_hash));
        $this->assertNotSame($notification->code, $challenge->code_hash);
        $this->assertTrue($challenge->expires_at->equalTo(now()->addMinutes(60)));
        $this->assertStringNotContainsString($notification->code, $response->getContent());
    }

    public function test_returns_422_for_missing_registration_fields_without_creating_an_account(): void
    {
        Notification::fake();

        $response = $this->postJson(route('auth.register'), []);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'first_name', 'last_name', 'email', 'username', 'password', 'password_confirmation',
        ])->assertJsonPath('errors.first_name.0', 'The first name field is required.');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_otps', 0);
        Notification::assertNothingSent();
    }

    #[DataProvider('invalidRegistrationFields')]
    public function test_returns_422_for_invalid_registration_fields(string $field, mixed $value): void
    {
        Notification::fake();
        $payload = array_replace($this->registrationPayload(), [$field => $value]);

        $response = $this->postJson(route('auth.register'), $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidRegistrationFields(): array
    {
        return [
            'first name type' => ['first_name', ['Ada']],
            'first name length' => ['first_name', str_repeat('a', 256)],
            'last name length' => ['last_name', str_repeat('b', 256)],
            'email format' => ['email', 'invalid-address'],
            'email length' => ['email', str_repeat('a', 244).'@example.com'],
            'username type' => ['username', ['ada']],
            'username length' => ['username', str_repeat('a', 256)],
            'short password' => ['password', '1234567'],
            'password above bcrypt byte limit' => ['password', str_repeat('a', 73)],
            'unicode password above bcrypt byte limit' => ['password', str_repeat('é', 37)],
            'password with null byte' => ['password', "safe\0password"],
        ];
    }

    #[DataProvider('invalidPasswordConfirmations')]
    public function test_returns_422_for_missing_invalid_or_mismatched_password_confirmation(mixed $confirmation): void
    {
        Notification::fake();
        $payload = array_replace($this->registrationPayload(), ['password_confirmation' => $confirmation]);

        $this->postJson(route('auth.register'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('password_confirmation');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_otps', 0);
        Notification::assertNothingSent();
    }

    /** @return array<string, array{mixed}> */
    public static function invalidPasswordConfirmations(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'invalid type' => [['a secure password']],
            'mismatch' => ['different secure password'],
            'extra whitespace' => ['a secure password '],
        ];
    }

    public function test_preserves_matching_password_and_confirmation_whitespace(): void
    {
        Notification::fake();
        $payload = array_replace($this->registrationPayload(), [
            'password' => ' a secure password ',
            'password_confirmation' => ' a secure password ',
        ]);

        $this->postJson(route('auth.register'), $payload)->assertCreated();

        $user = User::where('email', $payload['email'])->sole();
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertFalse(Hash::check(trim($payload['password']), $user->password));
        $this->assertArrayNotHasKey('password_confirmation', $user->getAttributes());
        Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
    }

    public function test_returns_422_for_duplicate_email_and_username_without_overwriting_existing_account(): void
    {
        $existing = User::factory()->unverified()->create([
            'email' => 'ada@example.com',
            'username' => 'ada_lovelace',
        ]);
        $password = $existing->password;
        Notification::fake();

        $response = $this->postJson(route('auth.register'), $this->registrationPayload());

        $response->assertUnprocessable()->assertJsonValidationErrors(['email', 'username']);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($password, $existing->fresh()->password);
        Notification::assertNothingSent();
    }

    public function test_ignores_client_controlled_verification_status_and_identifier(): void
    {
        Notification::fake();
        $payload = array_merge($this->registrationPayload(), [
            'status' => 'inactive',
            'email_verified_at' => '2026-01-01 00:00:00',
            'id' => 999,
        ]);

        $this->postJson(route('auth.register'), $payload)->assertCreated();

        $user = User::where('email', $payload['email'])->sole();
        $this->assertSame('active', $user->getRawOriginal('status'));
        $this->assertNull($user->email_verified_at);
        $this->assertNotSame(999, $user->id);
    }

    public function test_accepts_eight_character_and_seventy_two_byte_password_boundaries_with_confirmation(): void
    {
        Notification::fake();
        $payload = array_replace($this->registrationPayload(), [
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ]);

        $this->postJson(route('auth.register'), $payload)->assertCreated();
        $this->postJson(route('auth.register'), array_replace($payload, [
            'email' => 'second@example.com',
            'username' => 'second',
            'password' => str_repeat('é', 36),
            'password_confirmation' => str_repeat('é', 36),
        ]))->assertCreated();

        $this->assertDatabaseCount('users', 2);
    }

    public function test_hashes_a_bcrypt_looking_password_as_literal_input_and_accepts_it_at_login(): void
    {
        $this->createPassportClient();
        Notification::fake();
        $literalPassword = Hash::make('underlying secret');
        $payload = array_replace($this->registrationPayload(), [
            'password' => $literalPassword,
            'password_confirmation' => $literalPassword,
        ]);

        $this->postJson(route('auth.register'), $payload)->assertCreated();

        $user = User::where('email', $payload['email'])->sole();
        $this->assertTrue(Hash::check($literalPassword, $user->password));
        $this->assertFalse(Hash::check('underlying secret', $user->password));
        $this->postJson(route('auth.verify-email'), [
            'email' => $user->email,
            'otp' => $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION)->code,
        ])->assertOk();
        $this->forgetAuthenticatedUser();
        $this->postJson(route('auth.login'), [
            'username' => $user->username,
            'password' => $literalPassword,
        ])->assertOk()->assertPlainCookie('auth_token');
    }
}
