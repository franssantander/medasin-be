<?php

namespace Tests\Feature\Profile;

use App\Enum\AuthOtpPurpose;
use App\Jobs\Profile\PurgeAccountFiles;
use App\Models\AuthOtp;
use App\Models\Letter;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Auth\TokenService;
use App\Services\Profile\FileCleanupService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Auth\AuthTestCase;

class ProfileTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_current_user_includes_nullable_image_url_without_storage_path(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->getJson(route('auth.me'))->assertOk()
            ->assertJsonPath('data.profile_image_url', null)
            ->assertJsonMissingPath('data.profile_image_path');
    }

    #[DataProvider('allowedImageTypes')]
    public function test_profile_image_upload_uses_an_owned_generated_path(string $extension, string $mime): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Passport::actingAs($user);

        $response = $this->post(route('profile.image.store'), [
            'image' => UploadedFile::fake()->create("photo.{$extension}", 12, $mime),
            'user_id' => $other->id,
            'profile_image_path' => 'other-user.png',
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $user->refresh()->profile_image_path;
        $this->assertStringStartsWith("profiles/{$user->uuid}/", $path);
        $this->assertStringEndsWith(".{$extension}", $path);
        $this->assertStringNotContainsString('photo', $path);
        Storage::disk('public')->assertExists($path);
        $response->assertJsonPath('data.uuid', $user->uuid)
            ->assertJsonPath('data.profile_image_url', url(Storage::disk('public')->url($path)))
            ->assertJsonMissingPath('data.profile_image_path');
        $this->assertNull($other->refresh()->profile_image_path);
        $this->assertDatabaseCount('jobs', 0);
    }

    public static function allowedImageTypes(): array
    {
        return [
            'jpeg' => ['jpg', 'image/jpeg'],
            'png' => ['png', 'image/png'],
            'webp' => ['webp', 'image/webp'],
        ];
    }

    #[DataProvider('invalidImageTypes')]
    public function test_invalid_images_preserve_the_previous_image(?string $filename, int $size, ?string $mime): void
    {
        $user = $this->userWithImage();
        $previous = $user->profile_image_path;
        Passport::actingAs($user);
        $payload = $filename === null ? [] : ['image' => UploadedFile::fake()->create($filename, $size, $mime)];

        $this->post(route('profile.image.store'), $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->assertSame($previous, $user->refresh()->profile_image_path);
        Storage::disk('public')->assertExists($previous);
        $this->assertDatabaseCount('jobs', 0);
    }

    public static function invalidImageTypes(): array
    {
        return [
            'missing' => [null, 0, null],
            'too large' => ['photo.png', 5121, 'image/png'],
            'svg' => ['photo.svg', 2, 'image/svg+xml'],
            'gif' => ['photo.gif', 2, 'image/gif'],
            'text with png extension' => ['photo.png', 2, 'text/plain'],
        ];
    }

    public function test_replacing_an_image_keeps_the_old_file_until_the_durable_job_runs(): void
    {
        $user = $this->userWithImage();
        $oldPath = $user->profile_image_path;
        Passport::actingAs($user);

        $this->post(route('profile.image.store'), ['image' => $this->png()], ['Accept' => 'application/json'])->assertOk();

        $newPath = $user->refresh()->profile_image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertExists([$oldPath, $newPath]);
        $this->assertDatabaseCount('jobs', 1);
        $this->runCleanupJob();
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_removing_a_profile_image_is_idempotent_and_preserves_other_users_files(): void
    {
        $user = $this->userWithImage();
        $other = $this->userWithImage();
        $oldPath = $user->profile_image_path;
        Passport::actingAs($user);

        $this->deleteJson(route('profile.image.destroy'))->assertOk()->assertJsonPath('data.profile_image_url', null);
        $this->deleteJson(route('profile.image.destroy'))->assertOk()->assertJsonPath('data.profile_image_url', null);

        $this->assertNull($user->refresh()->profile_image_path);
        $this->assertDatabaseCount('jobs', 1);
        Storage::disk('public')->assertExists($oldPath);
        $this->runCleanupJob();
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($other->profile_image_path);
    }

    #[DataProvider('partialStorageFailures')]
    public function test_partial_storage_failure_removes_the_new_file_and_keeps_the_previous_image(bool $throws): void
    {
        $user = $this->userWithImage();
        $disk = Storage::disk('public');
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('putFileAs')->once()->andReturnUsing(function (string $directory, UploadedFile $file, string $filename) use ($disk, $user, $throws): bool {
            $this->assertSame("profiles/{$user->uuid}", $directory);
            $this->assertSame($file->hashName(), $filename);
            $disk->put("{$directory}/{$filename}", 'partially stored image');

            if ($throws) {
                throw new RuntimeException('Simulated storage metadata failure.');
            }

            return false;
        });
        $mock->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use ($disk, $user): bool {
            $this->assertNotSame($user->profile_image_path, $path);

            return $disk->delete($path);
        });
        Storage::set('public', $mock);
        Passport::actingAs($user);

        $this->post(route('profile.image.store'), ['image' => $this->png()], ['Accept' => 'application/json'])->assertServerError();

        Storage::set('public', $disk);
        $this->assertSame($user->profile_image_path, $user->fresh()->profile_image_path);
        $disk->assertExists($user->profile_image_path);
        $this->assertSame([$user->profile_image_path], $disk->allFiles("profiles/{$user->uuid}"));
        $this->assertDatabaseCount('jobs', 0);
    }

    public static function partialStorageFailures(): array
    {
        return ['returns false after writing' => [false], 'throws after writing' => [true]];
    }

    public function test_failed_cleanup_enqueue_rolls_back_image_replacement_and_removes_the_new_file(): void
    {
        $user = $this->userWithImage();
        $this->useMismatchedCleanupConnection();
        Passport::actingAs($user);

        $this->post(route('profile.image.store'), ['image' => $this->png()], ['Accept' => 'application/json'])->assertServerError();

        $this->assertSame($user->profile_image_path, $user->fresh()->profile_image_path);
        $this->assertSame([$user->profile_image_path], Storage::disk('public')->allFiles("profiles/{$user->uuid}"));
        $this->assertDatabaseCount('jobs', 0);
    }

    #[DataProvider('rememberPreferences')]
    public function test_password_change_rotates_only_this_browser_preserving_its_remember_preference(bool $remember, bool $secure): void
    {
        $this->app->instance('env', $secure ? 'production' : 'testing');
        $this->createPassportClient();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $tokens = app(TokenService::class);
        [$access, $refresh] = $tokens->issue($user, $remember);
        $tokens->issue($user, ! $remember);
        $tokens->issue($other, true);
        $oldTokenIds = $user->tokens()->pluck('id');
        DB::table('oauth_refresh_tokens')->insert([
            'id' => Str::random(80), 'access_token_id' => $oldTokenIds->first(),
            'revoked' => false, 'expires_at' => now()->addDay(),
        ]);
        Password::broker()->createToken($user);
        $this->createResetOtp($user);
        $verificationOtp = $this->createResetOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
        foreach ([$user, $other] as $account) {
            DB::table('sessions')->insert([
                'id' => "session-{$account->id}", 'user_id' => $account->id,
                'payload' => '', 'last_activity' => time(),
            ]);
            DB::table('personal_access_tokens')->insert([
                'tokenable_type' => $account->getMorphClass(), 'tokenable_id' => $account->id,
                'name' => 'test', 'token' => hash('sha256', "sanctum-{$account->id}"),
            ]);
        }

        $response = $this->withUnencryptedCookie('auth_token', $access->getValue())
            ->patchJson(route('profile.password.update'), $this->passwordPayload())
            ->assertOk()->assertJsonPath('data', null)
            ->assertJsonPath('message', 'Password updated. You remain signed in on this browser.');

        $this->assertTrue(Hash::check('a new secure password', $user->refresh()->password));
        $this->assertSame(0, $user->tokens()->whereIn('id', $oldTokenIds)->where('revoked', false)->count());
        $this->assertSame(1, $user->tokens()->where('revoked', false)->count());
        $this->assertSame(1, $other->tokens()->where('revoked', false)->count());
        $this->assertDatabaseMissing('oauth_refresh_tokens', ['revoked' => false]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('auth_otps', ['user_id' => $user->id, 'purpose' => AuthOtpPurpose::PASSWORD_RESET->value]);
        $this->assertDatabaseHas('auth_otps', ['id' => $verificationOtp->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_type' => $user->getMorphClass(), 'tokenable_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_type' => $other->getMorphClass(), 'tokenable_id' => $other->id]);
        $newRefresh = RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->sole();
        $this->assertSame($remember, $newRefresh->remember_me);
        $this->assertNull($tokens->rotate($refresh->getValue()));
        $newAccess = $response->getCookie('auth_token', false);
        $newRefreshCookie = $response->getCookie('refresh_token', false);
        $this->assertSame($remember, $newAccess->getExpiresTime() > 0);
        $this->assertSame($remember, $newRefreshCookie->getExpiresTime() > 0);
        $this->assertSame('/', $newAccess->getPath());
        $this->assertSame('/api/v1/auth', $newRefreshCookie->getPath());
        foreach ([$newAccess, $newRefreshCookie] as $cookie) {
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame('strict', $cookie->getSameSite());
            $this->assertSame($secure, $cookie->isSecure());
        }

        $this->forgetAuthenticatedUser();
        $this->withUnencryptedCookie('auth_token', $access->getValue())->getJson(route('auth.me'))->assertUnauthorized();
        $this->forgetAuthenticatedUser();
        $this->withUnencryptedCookie('auth_token', $newAccess->getValue())
            ->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.uuid', $user->uuid);
    }

    public static function rememberPreferences(): array
    {
        return [
            'remembered development' => [true, false],
            'session development' => [false, false],
            'remembered production' => [true, true],
            'session production' => [false, true],
        ];
    }

    public function test_password_change_without_a_paired_custom_refresh_token_defaults_to_a_session_cookie(): void
    {
        $this->createPassportClient();
        $user = User::factory()->create();
        $token = $user->createToken('external browser')->accessToken;

        $response = $this->withToken($token)->patchJson(route('profile.password.update'), $this->passwordPayload())->assertOk();

        $this->assertSame(0, $response->getCookie('auth_token', false)->getExpiresTime());
        $this->assertFalse(RefreshToken::query()->whereNull('revoked_at')->sole()->remember_me);
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_changes_leave_the_password_unchanged(array $overrides, string $field): void
    {
        $user = User::factory()->create();
        $hash = $user->password;
        Passport::actingAs($user);

        $this->patchJson(route('profile.password.update'), array_replace($this->passwordPayload(), $overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame($hash, $user->refresh()->password);
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public static function invalidPasswords(): array
    {
        return [
            'wrong current password' => [['current_password' => 'incorrect'], 'current_password'],
            'missing current password' => [['current_password' => null], 'current_password'],
            'short password' => [['password' => '1234567', 'password_confirmation' => '1234567'], 'password'],
            'mismatched confirmation' => [['password_confirmation' => 'different'], 'password'],
            'missing confirmation' => [['password_confirmation' => null], 'password_confirmation'],
            'utf8 exceeds bcrypt byte limit' => [['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)], 'password'],
            'null byte' => [['password' => "password\0invalid", 'password_confirmation' => "password\0invalid"], 'password'],
        ];
    }

    #[DataProvider('invalidDeletionConfirmations')]
    public function test_account_deletion_requires_exact_literal_confirmation(array $payload): void
    {
        $user = $this->userWithImage();
        Passport::actingAs($user);

        $this->deleteJson(route('profile.destroy'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation')
            ->assertJsonMissingPath('errors.current_password');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        Storage::disk('public')->assertExists($user->profile_image_path);
        $this->assertDatabaseCount('jobs', 0);
    }

    public static function invalidDeletionConfirmations(): array
    {
        return [
            'lowercase confirmation' => [['confirmation' => 'delete']],
            'whitespace around confirmation' => [['confirmation' => ' DELETE ']],
            'missing confirmation' => [[]],
        ];
    }

    public function test_account_deletion_removes_owned_data_and_authentication_but_preserves_other_users_and_global_clients(): void
    {
        $this->createPassportClient();
        $globalClient = Client::query()->sole();
        $user = $this->userWithImage();
        $other = $this->userWithImage();
        $tokens = app(TokenService::class);
        [$access] = $tokens->issue($user, true);
        $tokens->issue($other, true);
        $ownerTokenId = $user->tokens()->sole()->id;
        $otherTokenId = $other->tokens()->sole()->id;
        Password::broker()->createToken($user);
        Password::broker()->createToken($other);
        $this->createResetOtp($user);
        $this->createResetOtp($other);
        $ownedClient = Client::factory()->create(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id]);
        foreach ([$user, $other] as $account) {
            $account->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['message' => 'test']]);
            DB::table('sessions')->insert(['id' => "session-{$account->id}", 'user_id' => $account->id, 'payload' => '', 'last_activity' => time()]);
            DB::table('personal_access_tokens')->insert([
                'tokenable_type' => $account->getMorphClass(), 'tokenable_id' => $account->id,
                'name' => 'test', 'token' => hash('sha256', "sanctum-{$account->id}"),
            ]);
            DB::table('oauth_auth_codes')->insert([
                'id' => "auth-{$account->id}", 'user_id' => $account->id, 'client_id' => $globalClient->id,
                'scopes' => '[]', 'revoked' => false, 'expires_at' => now()->addDay(),
            ]);
            DB::table('oauth_device_codes')->insert([
                'id' => "device-{$account->id}", 'user_id' => $account->id, 'client_id' => $globalClient->id,
                'user_code' => str_pad((string) $account->id, 8, '0'), 'scopes' => '[]', 'revoked' => false,
                'expires_at' => now()->addDay(),
            ]);
        }
        DB::table('oauth_refresh_tokens')->insert([
            'id' => 'native-owner', 'access_token_id' => $ownerTokenId, 'revoked' => false, 'expires_at' => now()->addDay(),
        ]);
        $area = $user->areas()->create(['name' => 'Archived area', 'background_image' => 'areas/backgrounds/owned.png', 'archived_at' => now()]);
        $note = $area->notes()->create(['title' => 'Deleted note', 'content' => '<p>Deleted</p>']);
        $notePath = "areas/{$area->uuid}/notes/{$note->uuid}/owned.png";
        $note->media()->create($this->mediaAttributes($notePath));
        $note->delete();
        $standaloneNote = $user->notes()->create(['title' => 'Standalone note', 'content' => '<p>Standalone</p>']);
        $standalonePath = "notes/{$user->uuid}/{$standaloneNote->uuid}/owned.png";
        $standaloneNote->media()->create($this->mediaAttributes($standalonePath));
        $area->delete();
        $letter = Letter::factory()->for($user)->create();
        $letterPath = "letters/{$user->uuid}/{$letter->uuid}/owned.png";
        $letter->media()->create($this->mediaAttributes($letterPath));
        $letter->delete();
        $resource = $user->resources()->create(['title' => 'Archived resource', 'type' => 'file', 'archived_at' => now()]);
        $attachmentPath = "resources/{$resource->uuid}/owned.pdf";
        $attachment = $resource->attachments()->create(['kind' => 'file', ...$this->mediaAttributes($attachmentPath)]);
        $attachment->delete();
        $resource->delete();
        $user->trashEntries()->create([
            'subject_type' => $note::class, 'subject_id' => $note->id, 'subject_uuid' => $note->uuid,
            'item_type' => 'note', 'title' => 'Deleted note', 'deleted_at' => now(), 'expires_at' => now()->addDays(30),
        ]);
        $publicPaths = [$user->profile_image_path, 'areas/backgrounds/owned.png', $notePath, $standalonePath, $letterPath,
            "profiles/{$user->uuid}/orphan.png", "letters/{$user->uuid}/orphan.png", "areas/{$area->uuid}/notes/orphan.png"];
        foreach ($publicPaths as $path) {
            Storage::disk('public')->put($path, 'owned');
        }
        Storage::disk('local')->put($attachmentPath, 'owned');
        Storage::disk('local')->put("resources/{$resource->uuid}/orphan.pdf", 'owned');
        Storage::disk('public')->put('areas/backgrounds/other.png', 'other');

        $response = $this->withUnencryptedCookie('auth_token', $access->getValue())
            ->deleteJson(route('profile.destroy'), ['confirmation' => 'DELETE', 'user_id' => $other->id])
            ->assertOk()->assertJsonPath('data', null);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        foreach (['areas', 'notes', 'letters', 'resources', 'trash_entries', 'auth_otps', 'refresh_tokens', 'sessions', 'oauth_auth_codes', 'oauth_device_codes'] as $table) {
            $this->assertDatabaseMissing($table, ['user_id' => $user->id]);
        }
        $this->assertDatabaseMissing('note_media', ['note_id' => $note->id]);
        $this->assertDatabaseMissing('letter_media', ['letter_id' => $letter->id]);
        $this->assertDatabaseMissing('resource_attachments', ['resource_id' => $resource->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $ownerTokenId]);
        $this->assertDatabaseMissing('oauth_refresh_tokens', ['id' => 'native-owner']);
        $this->assertDatabaseMissing('oauth_clients', ['id' => $ownedClient->id]);
        $this->assertDatabaseHas('oauth_clients', ['id' => $globalClient->id]);
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $otherTokenId, 'revoked' => false]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $other->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $other->id]);
        $this->assertDatabaseHas('oauth_auth_codes', ['user_id' => $other->id]);
        $this->assertDatabaseHas('oauth_device_codes', ['user_id' => $other->id]);
        $this->assertLessThan(time(), $response->getCookie('auth_token', false)->getExpiresTime());
        $this->assertSame('/api/v1/auth', $response->getCookie('refresh_token', false)->getPath());
        $this->assertDatabaseCount('jobs', 1);
        Storage::disk('public')->assertExists($publicPaths);
        Storage::disk('local')->assertExists($attachmentPath);
        $this->runCleanupJob();
        Storage::disk('public')->assertMissing($publicPaths);
        Storage::disk('local')->assertDirectoryEmpty("resources/{$resource->uuid}");
        Storage::disk('public')->assertExists([$other->profile_image_path, 'areas/backgrounds/other.png']);
        $this->assertDatabaseCount('jobs', 0);

        $this->forgetAuthenticatedUser();
        $this->withUnencryptedCookie('auth_token', $access->getValue())->getJson(route('auth.me'))->assertUnauthorized();
    }

    public function test_account_deletion_fails_safely_when_cleanup_cannot_join_the_account_transaction(): void
    {
        $this->createPassportClient();
        $user = $this->userWithImage();
        app(TokenService::class)->issue($user, true);
        Password::broker()->createToken($user);
        $this->useMismatchedCleanupConnection();
        Passport::actingAs($user);

        $this->deleteJson(route('profile.destroy'), ['confirmation' => 'DELETE'])->assertServerError();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(1, $user->tokens()->where('revoked', false)->count());
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('refresh_tokens', ['user_id' => $user->id, 'revoked_at' => null]);
        Storage::disk('public')->assertExists($user->profile_image_path);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_an_exception_after_cleanup_enqueue_rolls_back_the_job_and_account_auth_changes(): void
    {
        $this->createPassportClient();
        $user = $this->userWithImage();
        app(TokenService::class)->issue($user, true);
        Password::broker()->createToken($user);
        $this->createResetOtp($user);
        Passport::actingAs($user);
        User::deleting(function (User $deleting): void {
            $this->assertDatabaseCount('jobs', 1);
            $this->assertSame(0, $deleting->tokens()->count());

            throw new RuntimeException('Simulated account deletion failure.');
        });

        try {
            $this->deleteJson(route('profile.destroy'), ['confirmation' => 'DELETE'])
                ->assertServerError();
        } finally {
            Event::forget('eloquent.deleting: '.User::class);
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(1, $user->tokens()->where('revoked', false)->count());
        $this->assertDatabaseHas('refresh_tokens', ['user_id' => $user->id, 'revoked_at' => null]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('auth_otps', ['user_id' => $user->id]);
        Storage::disk('public')->assertExists($user->profile_image_path);
        $this->assertDatabaseCount('jobs', 0);
    }

    #[DataProvider('protectedEndpoints')]
    public function test_profile_endpoints_require_authentication_and_verified_email(string $method, string $route): void
    {
        $this->json($method, route($route))->assertUnauthorized();
        Passport::actingAs(User::factory()->unverified()->create());
        $this->json($method, route($route))->assertForbidden();
        $this->assertDatabaseCount('jobs', 0);
    }

    public static function protectedEndpoints(): array
    {
        return [
            'upload' => ['POST', 'profile.image.store'],
            'remove' => ['DELETE', 'profile.image.destroy'],
            'password' => ['PATCH', 'profile.password.update'],
            'account deletion' => ['DELETE', 'profile.destroy'],
        ];
    }

    public function test_failed_file_removal_is_queued_and_can_retry_idempotently_after_user_deletion(): void
    {
        $disk = Storage::disk('public');
        $disk->put('profiles/deleted/avatar.png', 'image');
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('delete')->once()->with('profiles/deleted/avatar.png')->andReturn(false);
        Storage::set('public', $mock);

        app(FileCleanupService::class)->deleteOrQueue(['public' => ['profiles/deleted/avatar.png']]);

        $this->assertDatabaseCount('jobs', 1);
        $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command']);
        $this->assertInstanceOf(PurgeAccountFiles::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30, 90], $job->backoff);
        $this->assertSame(60, $job->timeout);
        Storage::set('public', $disk);
        $this->runCleanupJob();
        $job->handle();
        $disk->assertMissing('profiles/deleted/avatar.png');
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_a_cleanup_job_throws_when_a_disk_reports_failure(): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('delete')->once()->with('owned.png')->andReturn(false);
        Storage::set('public', $mock);

        $this->expectException(RuntimeException::class);
        (new PurgeAccountFiles(['public' => ['owned.png']]))->handle();
    }

    private function userWithImage(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['profile_image_path' => "profiles/{$user->uuid}/original.png"])->save();
        Storage::disk('public')->put($user->profile_image_path, 'existing image');

        return $user;
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }

    private function passwordPayload(): array
    {
        return ['current_password' => 'password', 'password' => 'a new secure password', 'password_confirmation' => 'a new secure password'];
    }

    private function createResetOtp(User $user, AuthOtpPurpose $purpose = AuthOtpPurpose::PASSWORD_RESET): AuthOtp
    {
        return AuthOtp::create([
            'user_id' => $user->id, 'purpose' => $purpose, 'code_hash' => Hash::make('012345'),
            'version' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(10), 'sent_at' => now(),
        ]);
    }

    private function mediaAttributes(string $path): array
    {
        return ['path' => $path, 'original_name' => basename($path), 'mime_type' => 'image/png', 'size' => 5];
    }

    private function useMismatchedCleanupConnection(): void
    {
        config([
            'database.connections.cleanup_other' => config('database.connections.sqlite'),
            'queue.connections.database.connection' => 'cleanup_other',
        ]);
    }

    private function runCleanupJob(): void
    {
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $job->fire();
    }
}
