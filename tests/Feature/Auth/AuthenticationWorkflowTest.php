<?php

use App\Enum\AuthOtpPurpose;
use App\Models\User;
use App\Notifications\AuthOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

pest()->group('pest-features');

it('registers verifies and revokes a cookie session on logout', function (): void {
    Notification::fake([AuthOtpNotification::class]);
    $this->createPassportClient();
    $payload = $this->registrationPayload();

    $this->postJson(route('auth.register'), $payload)->assertCreated();
    $user = User::where('email', $payload['email'])->sole();
    $otp = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
    Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
    $verified = $this->postJson(route('auth.verify-email'), [
        'email' => $user->email,
        'otp' => $otp->code,
        'remember_me' => false,
    ])->assertOk()->assertPlainCookie('auth_token')->assertPlainCookie('refresh_token');

    $cookies = [
        'auth_token' => $verified->getCookie('auth_token', false)->getValue(),
        'refresh_token' => $verified->getCookie('refresh_token', false)->getValue(),
    ];
    $this->forgetAuthenticatedUser();
    $this->withUnencryptedCookies($cookies)->getJson(route('auth.me'))
        ->assertOk()->assertJsonPath('data.email', $payload['email']);
    $this->postJson(route('auth.logout'))->assertOk()->assertCookieExpired('auth_token');
    $this->assertDatabaseMissing('oauth_access_tokens', ['user_id' => $user->id, 'revoked' => false]);
    $this->assertDatabaseMissing('refresh_tokens', ['user_id' => $user->id, 'revoked_at' => null]);
    $this->forgetAuthenticatedUser();
    $this->withUnencryptedCookies($cookies)->getJson(route('auth.me'))->assertUnauthorized();
});

it('recovers a password and signs in with the replacement credentials', function (): void {
    Notification::fake([AuthOtpNotification::class]);
    $this->createPassportClient();
    $user = User::factory()->create();

    $token = $this->requestResetToken($user);
    $this->postJson(route('auth.reset-password'), $this->resetPayload($user, $token))->assertOk();
    expect(Hash::check('new secure password', $user->fresh()->password))->toBeTrue();
    Notification::assertSentToTimes($user, AuthOtpNotification::class, 1);
    $this->postJson(route('auth.login'), ['username' => $user->username, 'password' => 'password'])
        ->assertUnprocessable()->assertCookieMissing('auth_token');
    $login = $this->postJson(route('auth.login'), [
        'username' => $user->username,
        'password' => 'new secure password',
    ])->assertOk()->assertPlainCookie('auth_token');

    $this->forgetAuthenticatedUser();
    $this->withUnencryptedCookie('auth_token', $login->getCookie('auth_token', false)->getValue())
        ->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.id', $user->id);
});
