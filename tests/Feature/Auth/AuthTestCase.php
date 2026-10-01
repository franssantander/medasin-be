<?php

namespace Tests\Feature\Auth;

use App\Enum\AuthOtpPurpose;
use App\Models\User;
use App\Notifications\AuthOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Client;
use Tests\TestCase;

abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var array{private: string, public: string}|null */
    private static ?array $passportKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$passportKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            openssl_pkey_export($key, $privateKey);
            self::$passportKeys = [
                'private' => $privateKey,
                'public' => openssl_pkey_get_details($key)['key'],
            ];
        }

        config([
            'passport.private_key' => self::$passportKeys['private'],
            'passport.public_key' => self::$passportKeys['public'],
            'app.debug' => false,
        ]);
        $this->withCredentials();
    }

    protected function createPassportClient(): void
    {
        Client::factory()->asPersonalAccessTokenClient()->create(['provider' => 'users']);
    }

    protected function latestOtp(User $user, AuthOtpPurpose $purpose): AuthOtpNotification
    {
        $notification = Notification::sent(
            $user,
            AuthOtpNotification::class,
            fn (AuthOtpNotification $notification): bool => $notification->purpose === $purpose,
        )->last();

        $this->assertInstanceOf(AuthOtpNotification::class, $notification);

        return $notification;
    }

    protected function forgetAuthenticatedUser(): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->withoutHeader('Authorization');
    }

    /** @return array{first_name: string, last_name: string, email: string, username: string, password: string, password_confirmation: string} */
    protected function registrationPayload(): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'username' => 'ada_lovelace',
            'password' => 'a secure password',
            'password_confirmation' => 'a secure password',
        ];
    }

    /** @return array{email: string, reset_token: string, password: string, password_confirmation: string} */
    protected function resetPayload(User $user, string $token): array
    {
        return [
            'email' => $user->email,
            'reset_token' => $token,
            'password' => 'new secure password',
            'password_confirmation' => 'new secure password',
        ];
    }

    protected function requestResetToken(User $user): string
    {
        $this->postJson(route('auth.forgot-password'), ['email' => $user->email])->assertAccepted();
        $notification = $this->latestOtp($user, AuthOtpPurpose::PASSWORD_RESET);

        $response = $this->postJson(route('auth.verify-password-reset'), [
            'email' => $user->email,
            'otp' => $notification->code,
        ])->assertOk()->assertJsonPath('data.expires_in', 900);

        return $response->json('data.reset_token');
    }
}
