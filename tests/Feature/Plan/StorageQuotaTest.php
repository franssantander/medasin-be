<?php

use App\Models\Letter;
use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\TrashEntry;
use App\Models\User;
use App\Services\Plan\PlanQuotaService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

const STORAGE_TEST_LIMIT_BYTES = 1024 * 1024;

function capFreeStorageAtOneMegabyte(): void
{
    $plan = Plan::where('slug', 'free')->sole();
    $plan->updateOrFail(['limits' => [...$plan->limits, 'storage_mb' => 1]]);
}

function storageUpload(string $name = 'photo.png', int $kilobytes = 600): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, 'image/png');
}

/** @return array{0: Closure(UploadedFile): TestResponse, 1: string, 2: string} upload, disk, table */
function storageUploadPath(User $user, string $path): array
{
    $test = test();

    return match ($path) {
        'resource create' => [
            fn (UploadedFile $file): TestResponse => $test->postJson(route('resource.store'), ['title' => 'Reference '.uniqid(), 'files' => [$file]]),
            'local', 'resource_attachments',
        ],
        'resource attachment' => (function () use ($test, $user): array {
            $resource = $user->resources()->create(['title' => 'Reference']);

            return [
                fn (UploadedFile $file): TestResponse => $test->postJson(route('resource.attachments.store', $resource), ['files' => [$file]]),
                'local', 'resource_attachments',
            ];
        })(),
        'standalone note media' => (function () use ($test, $user): array {
            $note = $user->notes()->create(['title' => 'Note', 'content' => '[]']);

            return [
                fn (UploadedFile $file): TestResponse => $test->postJson(route('notes.media.store', $note->uuid), ['file' => $file]),
                'public', 'note_media',
            ];
        })(),
        'area note media' => (function () use ($test, $user): array {
            $area = $user->areas()->create(['name' => 'Health']);
            $note = $area->notes()->create(['title' => 'Note', 'content' => '[]']);

            return [
                fn (UploadedFile $file): TestResponse => $test->postJson(route('area.notes.media.store', [$area, $note]), ['file' => $file]),
                'public', 'note_media',
            ];
        })(),
        'letter media' => (function () use ($test, $user): array {
            $letter = Letter::factory()->for($user)->create();

            return [
                fn (UploadedFile $file): TestResponse => $test->postJson(route('letters.media.store', $letter->uuid), ['file' => $file]),
                'public', 'letter_media',
            ];
        })(),
    };
}

it('allows uploads up to the storage cap and denies the next one before writing it', function (string $path): void {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(PlanSeeder::class);
    capFreeStorageAtOneMegabyte();
    $user = User::factory()->create();
    Passport::actingAs($user);
    [$upload, $disk, $table] = storageUploadPath($user, $path);

    $upload(storageUpload('first.png'))->assertSuccessful();
    $files = Storage::disk($disk)->allFiles();
    $rows = DB::table($table)->count();

    $upload(storageUpload('second.png'))->assertForbidden()
        ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED')
        ->assertJsonPath('meta.feature', 'storage')
        ->assertJsonPath('meta.usage', 600 * 1024)
        ->assertJsonPath('meta.limit', STORAGE_TEST_LIMIT_BYTES);

    expect(Storage::disk($disk)->allFiles())->toBe($files)
        ->and(DB::table($table)->count())->toBe($rows);
})->with(['resource create', 'resource attachment', 'standalone note media', 'area note media', 'letter media']);

it('does not create the resource when its files exceed the storage cap', function (): void {
    Storage::fake('local');
    $this->seed(PlanSeeder::class);
    capFreeStorageAtOneMegabyte();
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson(route('resource.store'), [
        'title' => 'Too large', 'files' => [storageUpload('a.png'), storageUpload('b.png')],
    ])->assertForbidden()->assertJsonPath('meta.feature', 'storage');

    $this->assertDatabaseEmpty('resources');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('counts trashed files until they are permanently deleted', function (): void {
    Storage::fake('local');
    $this->seed(PlanSeeder::class);
    capFreeStorageAtOneMegabyte();
    $user = User::factory()->create();
    Passport::actingAs($user);
    $uuid = $this->postJson(route('resource.store'), ['title' => 'Old', 'files' => [storageUpload()]])
        ->assertCreated()->json('data.uuid');

    $this->deleteJson(route('resource.destroy', $uuid))->assertOk();
    $this->postJson(route('resource.store'), ['title' => 'New', 'files' => [storageUpload()]])
        ->assertForbidden()->assertJsonPath('meta.feature', 'storage');

    $entry = TrashEntry::query()->where('subject_uuid', $uuid)->sole();
    $this->deleteJson(route('trash.destroy', $entry))->assertOk();

    $this->postJson(route('resource.store'), ['title' => 'New', 'files' => [storageUpload()]])->assertCreated();
});

it('reports storage limits and usage in the subscription', function (): void {
    Storage::fake('public');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    Passport::actingAs($user);
    $letter = Letter::factory()->for($user)->create();
    $this->postJson(route('letters.media.store', $letter->uuid), ['file' => storageUpload(kilobytes: 120)])->assertCreated();

    $this->getJson(route('subscription.show'))->assertOk()
        ->assertJsonPath('data.limits.storage_bytes', 1024 * 1024 * 1024)
        ->assertJsonPath('data.usage.storage_bytes', 120 * 1024);
});

it('applies the paid tier storage cap', function (): void {
    Storage::fake('public');
    $this->seed(PlanSeeder::class);
    $clarity = Plan::where('slug', 'clarity')->sole();
    $clarity->updateOrFail(['limits' => [...$clarity->limits, 'storage_mb' => 1]]);
    $user = User::factory()->create();
    PlanAssignment::factory()->for($user)->for($clarity)->create(['ends_at' => now()->addMonth()]);
    Passport::actingAs($user);
    [$upload] = storageUploadPath($user, 'letter media');

    $upload(storageUpload('first.png'))->assertCreated();
    $upload(storageUpload('second.png'))->assertForbidden()->assertJsonPath('meta.feature', 'storage');
});

it('does not cap storage when enforcement is disabled', function (): void {
    config(['plans.enforcement_enabled' => false]);
    Storage::fake('public');
    $this->seed(PlanSeeder::class);
    capFreeStorageAtOneMegabyte();
    $user = User::factory()->create();
    Passport::actingAs($user);
    [$upload] = storageUploadPath($user, 'letter media');

    $upload(storageUpload('first.png'))->assertCreated();
    $upload(storageUpload('second.png'))->assertCreated();
    $this->getJson(route('subscription.show'))->assertJsonPath('data.limits.storage_bytes', null);
});

it('counts only the account owner files', function (): void {
    Storage::fake('public');
    $this->seed(PlanSeeder::class);
    capFreeStorageAtOneMegabyte();
    $other = User::factory()->create();
    Passport::actingAs($other);
    [$otherUpload] = storageUploadPath($other, 'letter media');
    $otherUpload(storageUpload())->assertCreated();
    $user = User::factory()->create();
    Passport::actingAs($user);
    [$upload] = storageUploadPath($user, 'letter media');

    $upload(storageUpload())->assertCreated();

    expect(app(PlanQuotaService::class)->storageUsage($user))->toBe(600 * 1024)
        ->and(app(PlanQuotaService::class)->storageUsage($other))->toBe(600 * 1024);
});
