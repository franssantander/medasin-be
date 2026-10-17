<?php

use App\Enum\AuthOtpPurpose;
use App\Models\RefreshToken;
use App\Models\User;
use App\Notifications\AccountDeletionNotification;
use App\Notifications\AuthOtpNotification;
use App\Notifications\SignedOutEverywhereNotification;
use App\Services\Auth\AuthOtpService;
use App\Services\Auth\TokenService;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;

pest()->group('pest-features');

it('only believes forwarded client addresses from trusted proxies', function (): void {
    Route::get('/api/v1/test-client-ip', fn (Request $request): array => ['ip' => $request->ip()]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeader('X-Forwarded-For', '198.51.100.7')
        ->getJson('/api/v1/test-client-ip')->assertJsonPath('ip', '203.0.113.9');
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])->withHeader('X-Forwarded-For', '198.51.100.7')
        ->getJson('/api/v1/test-client-ip')->assertJsonPath('ip', '198.51.100.7');
});

it('limits password guesses per account even when the client address changes', function (): void {
    User::factory()->create(['username' => 'target']);

    foreach (range(1, 10) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
            ->postJson(route('auth.login'), ['username' => 'target', 'password' => 'wrong guess'])
            ->assertUnprocessable();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->postJson(route('auth.login'), ['username' => ' TARGET ', 'password' => 'wrong guess'])
        ->assertTooManyRequests()->assertHeader('Retry-After');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->postJson(route('auth.login'), ['username' => 'someone-else', 'password' => 'wrong guess'])
        ->assertUnprocessable();
});

it('keeps counting wrong codes across resends and locks the purpose for an hour', function (): void {
    Notification::fake([AuthOtpNotification::class]);
    $user = User::factory()->unverified()->create();
    $otp = app(AuthOtpService::class);

    foreach (range(1, 3) as $round) {
        expect($otp->send($user, AuthOtpPurpose::EMAIL_VERIFICATION))->toBeTrue();
        $code = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION)->code;
        $wrong = $code === '000000' ? '111111' : '000000';
        foreach (range(1, 5) as $guess) {
            expect($otp->consume($user, AuthOtpPurpose::EMAIL_VERIFICATION, $wrong))->toBeFalse();
        }
        $this->travel(61)->seconds();
    }

    expect($otp->send($user, AuthOtpPurpose::EMAIL_VERIFICATION))->toBeFalse()
        ->and($otp->status($user, AuthOtpPurpose::EMAIL_VERIFICATION)['resend_after'])->toBeGreaterThan(3000);
    $this->travel(61)->minutes();
    expect($otp->send($user, AuthOtpPurpose::EMAIL_VERIFICATION))->toBeTrue();
    expect($otp->consume($user, AuthOtpPurpose::EMAIL_VERIFICATION, $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION)->code))->toBeTrue();
});

it('sends at most five codes per purpose in an hour', function (): void {
    Notification::fake([AuthOtpNotification::class]);
    $user = User::factory()->create();
    $otp = app(AuthOtpService::class);

    foreach (range(1, 5) as $send) {
        expect($otp->send($user, AuthOtpPurpose::PASSWORD_RESET))->toBeTrue();
        $this->travel(61)->seconds();
    }

    expect($otp->send($user, AuthOtpPurpose::PASSWORD_RESET))->toBeFalse();
    Notification::assertSentToTimes($user, AuthOtpNotification::class, 5);
    $this->travel(56)->minutes();
    expect($otp->send($user, AuthOtpPurpose::PASSWORD_RESET))->toBeTrue();
});

it('hashes the submitted password even when the username does not exist', function (): void {
    $hasher = Mockery::mock(app('hash'));
    $hasher->shouldReceive('check')->once()->andReturnFalse();
    Hash::swap($hasher);

    $this->postJson(route('auth.login'), ['username' => 'nobody', 'password' => 'any password'])
        ->assertUnprocessable()->assertJsonValidationErrors('username');
});

it('requires the current password or an emailed code to delete an account', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->deleteJson(route('profile.destroy'), ['confirmation' => 'DELETE'])
        ->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'otp']);
    $this->deleteJson(route('profile.destroy'), ['confirmation' => 'DELETE', 'current_password' => 'not my password'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->deleteJson(route('profile.destroy'), ['confirmation' => 'DELETE', 'otp' => '123456'])
        ->assertUnprocessable()->assertJsonValidationErrors('otp');

    expect($user->refresh()->deletion_scheduled_at)->toBeNull();
});

it('schedules deletion, signs out every session, and emails the owner', function (): void {
    Notification::fake([AccountDeletionNotification::class]);
    $this->createPassportClient();
    $this->freezeTime();
    $user = User::factory()->create(['username' => 'leaving']);
    $session = $this->postJson(route('auth.login'), ['username' => 'leaving', 'password' => 'password'])->assertOk();
    app(TokenService::class)->issue($user);
    $cookies = [
        'auth_token' => $session->getCookie('auth_token', false)->getValue(),
        'refresh_token' => $session->getCookie('refresh_token', false)->getValue(),
    ];
    $this->forgetAuthenticatedUser();

    $this->withUnencryptedCookies($cookies)
        ->deleteJson(route('profile.destroy'), ['confirmation' => 'DELETE', 'current_password' => 'password'])
        ->assertOk()->assertJsonPath('data.deletion_scheduled_at', now()->addDays(7)->toIso8601ZuluString())
        ->assertCookieExpired('auth_token');

    expect($user->refresh()->deletion_scheduled_at->toIso8601ZuluString())->toBe(now()->addDays(7)->toIso8601ZuluString())
        ->and(RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0);
    $this->forgetAuthenticatedUser();
    $this->withUnencryptedCookies($cookies)->getJson(route('auth.me'))->assertUnauthorized();
    $this->withUnencryptedCookies($cookies)->postJson(route('auth.refresh'))->assertUnauthorized();
    Notification::assertSentTo($user, AccountDeletionNotification::class,
        fn (AccountDeletionNotification $notification): bool => $notification->stage === AccountDeletionNotification::SCHEDULED);
});

it('confirms deletion for accounts without a known password using an emailed code', function (): void {
    Notification::fake([AuthOtpNotification::class, AccountDeletionNotification::class]);
    $user = User::factory()->create(['google_id' => 'google-123']);
    Passport::actingAs($user);

    $this->postJson(route('profile.deletion-code'))->assertOk()->assertJsonPath('data.sent', true);
    $code = $this->latestOtp($user, AuthOtpPurpose::ACCOUNT_DELETION)->code;
    $this->deleteJson(route('profile.destroy'), ['confirmation' => 'DELETE', 'otp' => $code === '000000' ? '111111' : '000000'])
        ->assertUnprocessable()->assertJsonValidationErrors('otp');
    $this->deleteJson(route('profile.destroy'), ['confirmation' => 'DELETE', 'otp' => $code])->assertOk();

    expect($user->refresh()->deletion_scheduled_at)->not->toBeNull();
});

it('cancels a scheduled deletion when the owner signs in again', function (): void {
    Notification::fake([AccountDeletionNotification::class]);
    $this->createPassportClient();
    $user = User::factory()->create(['username' => 'staying', 'deletion_scheduled_at' => now()->addDays(3)]);

    $this->postJson(route('auth.login'), ['username' => 'staying', 'password' => 'password'])->assertOk();
    $this->travel(8)->days();
    $this->artisan('accounts:purge-deleted')->assertSuccessful();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'deletion_scheduled_at' => null]);
    Notification::assertSentTo($user, AccountDeletionNotification::class,
        fn (AccountDeletionNotification $notification): bool => $notification->stage === AccountDeletionNotification::CANCELLED);
});

it('purges only accounts whose grace period has ended and emails their address', function (): void {
    Notification::fake([AccountDeletionNotification::class]);
    $due = User::factory()->create(['email' => 'due@example.com', 'deletion_scheduled_at' => now()->subMinute()]);
    $waiting = User::factory()->create(['deletion_scheduled_at' => now()->addDay()]);
    $kept = User::factory()->create();

    $this->artisan('accounts:purge-deleted')->assertSuccessful()->expectsOutput('Deleted 1 account(s).');

    $this->assertModelMissing($due);
    $this->assertModelExists($waiting);
    $this->assertModelExists($kept);
    Notification::assertSentOnDemand(AccountDeletionNotification::class,
        fn (AccountDeletionNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notification->stage === AccountDeletionNotification::COMPLETED
            && $notifiable->routes['mail'] === 'due@example.com');
});

it('revokes every session when a rotated refresh token is replayed after the race window', function (): void {
    Notification::fake([SignedOutEverywhereNotification::class]);
    $this->createPassportClient();
    $user = User::factory()->create();
    [, $stolen] = app(TokenService::class)->issue($user);
    app(TokenService::class)->issue($user);

    $this->withUnencryptedCookie('refresh_token', $stolen->getValue())->postJson(route('auth.refresh'))->assertOk();
    $this->withUnencryptedCookie('refresh_token', $stolen->getValue())->postJson(route('auth.refresh'))->assertUnauthorized();
    expect(RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(2);
    Notification::assertNothingSent();

    $this->travel(TokenService::REUSE_GRACE_SECONDS + 1)->seconds();
    $this->withUnencryptedCookie('refresh_token', $stolen->getValue())->postJson(route('auth.refresh'))->assertUnauthorized();

    expect(RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and($user->tokens()->where('revoked', false)->count())->toBe(0);
    Notification::assertSentTo($user, SignedOutEverywhereNotification::class);
});

it('does not treat a refresh token revoked by logout as theft', function (): void {
    Notification::fake([SignedOutEverywhereNotification::class]);
    $this->createPassportClient();
    $user = User::factory()->create();
    [, $loggedOut] = app(TokenService::class)->issue($user);
    app(TokenService::class)->issue($user);
    $tokenId = RefreshToken::where('token', hash('sha256', $loggedOut->getValue()))->value('access_token_id');
    app(TokenService::class)->revokeForAccessToken($user, $tokenId);
    $this->travel(5)->minutes();

    $this->withUnencryptedCookie('refresh_token', $loggedOut->getValue())->postJson(route('auth.refresh'))->assertUnauthorized();

    expect(RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(1);
    Notification::assertNothingSent();
});

it('stores and matches emails regardless of letter case', function (): void {
    Notification::fake([AuthOtpNotification::class]);
    $payload = [...$this->registrationPayload(), 'email' => '  Ada@Example.COM '];

    $this->postJson(route('auth.register'), $payload)->assertCreated();
    $user = User::sole();
    expect($user->email)->toBe('ada@example.com');

    $this->postJson(route('auth.register'), [...$payload, 'email' => 'ada@example.com', 'username' => 'another_ada'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->travel(61)->seconds();
    $this->postJson(route('auth.forgot-password'), ['email' => 'ADA@example.com'])->assertSuccessful();
    Notification::assertSentTo($user, AuthOtpNotification::class,
        fn (AuthOtpNotification $notification): bool => $notification->purpose === AuthOtpPurpose::PASSWORD_RESET);
});

it('rejects breached passwords in production', function (): void {
    $this->app->instance('env', 'production');
    $suffix = substr(strtoupper(sha1('a secure password')), 5);
    Http::fake(['api.pwnedpasswords.com/*' => Http::response("{$suffix}:42\r\n")]);

    $this->postJson(route('auth.register'), $this->registrationPayload())
        ->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('rejects cookie-authenticated writes from other browser origins', function (): void {
    config(['app.frontend_url' => 'https://app.example.com']);
    $this->createPassportClient();
    [$access] = app(TokenService::class)->issue(User::factory()->create());

    $this->withUnencryptedCookie('auth_token', $access->getValue())->withHeader('Origin', 'https://evil.example.com')
        ->postJson(route('auth.logout'))->assertForbidden();
    $this->withUnencryptedCookie('auth_token', $access->getValue())->withHeader('Origin', 'https://evil.example.com')
        ->getJson(route('auth.me'))->assertOk();
    $this->withUnencryptedCookie('auth_token', $access->getValue())->withHeader('Origin', 'https://app.example.com')
        ->postJson(route('auth.logout'))->assertOk();
});
