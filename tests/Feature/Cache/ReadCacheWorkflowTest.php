<?php

use App\Enum\PlanGrantType;
use App\Jobs\Profile\PurgeAccountFiles;
use App\Models\Plan;
use App\Models\ResourceAttachment;
use App\Models\TrashEntry;
use App\Models\User;
use App\Services\Plan\PlanAssignmentService;
use App\Services\Trash\TrashService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

pest()->use(DatabaseMigrations::class)->group('pest-features');

it('refreshes cached parent resource lists after resource links are cleared', function (string $store): void {
    config(['cache.api_reads.store' => $store, 'cache.api_reads.enabled' => true]);
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Research']);
    $project = $user->projects()->create(['name' => 'Research project']);
    Passport::actingAs($user);
    $uuid = $this->postJson(route('resource.store'), [
        'title' => 'Research reference', 'area_uuids' => [$area->uuid], 'project_uuids' => [$project->uuid],
    ])->assertCreated()->json('data.uuid');
    $resource = $user->resources()->where('uuid', $uuid)->sole();
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.data.0.uuid', $uuid);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonPath('data.resources.0.uuid', $uuid);
    $this->getJson(route('resource.show', $resource))->assertOk()->assertJsonCount(1, 'data.areas');

    $this->patchJson(route('resource.update', $resource), [
        'title' => 'Unlinked reference', 'area_uuids' => [], 'project_uuids' => [],
    ])->assertOk();
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonCount(0, 'data.resources');
    $this->getJson(route('resource.show', $resource))->assertOk()
        ->assertJsonPath('data.title', 'Unlinked reference')->assertJsonCount(0, 'data.areas')
        ->assertJsonCount(0, 'data.projects');

    $this->assertDatabaseHas('resources', ['id' => $resource->id, 'title' => 'Unlinked reference']);
    $this->assertDatabaseMissing('area_resource', ['resource_id' => $resource->id]);
    $this->assertDatabaseMissing('project_resource', ['resource_id' => $resource->id]);
})->with(['memory' => ['array'], 'database fallback' => ['database']]);

it('refreshes cached resource and parent views after trash deletion recovery and purge', function (string $store): void {
    config(['cache.api_reads.store' => $store, 'cache.api_reads.enabled' => true]);
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Research']);
    $project = $user->projects()->create(['name' => 'Research project']);
    $resource = $user->resources()->create(['title' => 'Recoverable reference']);
    $resource->areas()->attach($area);
    $resource->projects()->attach($project);
    Passport::actingAs($user);
    $revisionKey = 'api-read:revision:user:'.$user->uuid;
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.data.0.uuid', $resource->uuid);
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.data.0.uuid', $resource->uuid);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonPath('data.resources.0.uuid', $resource->uuid);
    $this->getJson(route('resource.show', $resource))->assertOk()->assertJsonPath('data.uuid', $resource->uuid);
    $revision = Cache::store('api_read_revisions')->get($revisionKey);

    $this->deleteJson(route('resource.destroy', $resource))->assertOk();

    $this->assertNotSame($revision, Cache::store('api_read_revisions')->get($revisionKey));
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonCount(0, 'data.resources');
    $this->getJson(route('resource.show', $resource))->assertNotFound();
    $entry = TrashEntry::query()->where('subject_uuid', $resource->uuid)->sole();
    $revision = Cache::store('api_read_revisions')->get($revisionKey);

    $this->postJson(route('trash.restore', $entry))->assertOk();

    $this->assertNotSame($revision, Cache::store('api_read_revisions')->get($revisionKey));
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.data.0.uuid', $resource->uuid);
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.data.0.uuid', $resource->uuid);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonPath('data.resources.0.uuid', $resource->uuid);
    $this->getJson(route('resource.show', $resource))->assertOk()->assertJsonPath('data.uuid', $resource->uuid);
    $this->deleteJson(route('resource.destroy', $resource))->assertOk();
    $entry = TrashEntry::query()->where('subject_uuid', $resource->uuid)->sole();
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonCount(0, 'data.resources');
    $revision = Cache::store('api_read_revisions')->get($revisionKey);

    $this->deleteJson(route('trash.destroy', $entry))->assertOk();

    $this->assertNotSame($revision, Cache::store('api_read_revisions')->get($revisionKey));
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonCount(0, 'data.resources');
    $this->getJson(route('resource.show', $resource))->assertNotFound();
    $this->assertModelMissing($resource);
    $this->assertModelMissing($entry);
})->with(['memory' => ['array'], 'database fallback' => ['database']]);

it('returns 500 and preserves resource rows trash and cache revisions after a failed trash insertion', function (): void {
    config(['cache.api_reads.store' => 'database', 'cache.api_reads.enabled' => true]);
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Reference']);
    Passport::actingAs($user);
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.data.0.uuid', $resource->uuid);
    $revisionKey = 'api-read:revision:user:'.$user->uuid;
    $revision = Cache::store('api_read_revisions')->get($revisionKey);
    TrashEntry::creating(static function (): void {
        throw new RuntimeException('Simulated trash insertion failure.');
    });

    try {
        $this->deleteJson(route('resource.destroy', $resource))->assertServerError();
    } finally {
        Event::forget('eloquent.creating: '.TrashEntry::class);
    }

    $this->assertDatabaseHas('resources', ['id' => $resource->id, 'deleted_at' => null]);
    $this->assertDatabaseCount('trash_entries', 0);
    $this->assertSame($revision, Cache::store('api_read_revisions')->get($revisionKey));
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.data.0.uuid', $resource->uuid);
    $this->getJson(route('resource.show', $resource))->assertOk()->assertJsonPath('data.uuid', $resource->uuid);
});

it('returns 500 and preserves trashed resources and cache revisions after failed recovery', function (): void {
    config(['cache.api_reads.store' => 'database', 'cache.api_reads.enabled' => true]);
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Reference']);
    $entry = app(TrashService::class)->delete($user, $resource, 'resource', $resource->title);
    Passport::actingAs($user);
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.total', 0);
    $revisionKey = 'api-read:revision:user:'.$user->uuid;
    $revision = Cache::store('api_read_revisions')->get($revisionKey);
    TrashEntry::deleting(static function (): void {
        throw new RuntimeException('Simulated trash removal failure.');
    });

    try {
        $this->postJson(route('trash.restore', $entry))->assertServerError();
    } finally {
        Event::forget('eloquent.deleting: '.TrashEntry::class);
    }

    $this->assertSoftDeleted($resource);
    $this->assertModelExists($entry);
    $this->assertSame($revision, Cache::store('api_read_revisions')->get($revisionKey));
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('resource.show', $resource))->assertNotFound();
});

it('queues durable resource file cleanup and removes child trash after a storage failure', function (bool $throws): void {
    Storage::fake('local');
    $disk = Storage::disk('local');
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Reference']);
    $paths = ["resources/{$resource->uuid}/active.pdf", "resources/{$resource->uuid}/trashed.pdf"];
    foreach ($paths as $path) {
        $disk->put($path, 'reference');
    }
    $resource->attachments()->create(['kind' => 'file', 'path' => $paths[0], 'original_name' => 'active.pdf']);
    $attachment = $resource->attachments()->create(['kind' => 'file', 'path' => $paths[1], 'original_name' => 'trashed.pdf']);
    $resource->attachments()->create(['kind' => 'link', 'url' => 'https://example.com/reference']);
    $childEntry = app(TrashService::class)->delete($user, $attachment, 'resource_attachment', 'trashed.pdf', $resource->title);
    $entry = app(TrashService::class)->delete($user, $resource, 'resource', $resource->title);
    $mock = Mockery::mock(FilesystemAdapter::class);
    $deletion = $mock->shouldReceive('delete')->once()->with(Mockery::on(fn (string $path): bool => in_array($path, $paths, true)));
    if ($throws) {
        $deletion->andThrow(new RuntimeException('Simulated storage failure.'));
    } else {
        $deletion->andReturn(false);
    }
    Storage::set('local', $mock);
    Passport::actingAs($user);

    try {
        $this->deleteJson(route('trash.destroy', $entry))->assertOk()->assertJsonPath('data', null);
    } finally {
        Storage::set('local', $disk);
    }

    $this->assertModelMissing($resource);
    $this->assertModelMissing($entry);
    $this->assertModelMissing($childEntry);
    $this->assertDatabaseCount('resource_attachments', 0);
    $this->assertDatabaseCount('jobs', 1);
    $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    $cleanup = unserialize($payload['data']['command']);
    $this->assertInstanceOf(PurgeAccountFiles::class, $cleanup);
    $queuedPaths = $cleanup->filesByDisk['local'];
    sort($paths);
    sort($queuedPaths);
    $this->assertSame($paths, $queuedPaths);
    $disk->assertExists($paths);

    $job = Queue::connection('database')->pop();
    $this->assertNotNull($job);
    $job->fire();

    $disk->assertMissing($paths);
    $this->assertDatabaseCount('jobs', 0);
})->with(['delete returns false' => [false], 'delete throws' => [true]]);

it('returns 500 and preserves resource trash files and revisions when cleanup cannot be queued', function (): void {
    config([
        'cache.api_reads.store' => 'database', 'cache.api_reads.enabled' => true,
        'database.connections.cleanup_other' => config('database.connections.sqlite'),
        'queue.connections.database.connection' => 'cleanup_other',
    ]);
    Storage::fake('local');
    $disk = Storage::disk('local');
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Reference']);
    $path = "resources/{$resource->uuid}/reference.pdf";
    $disk->put($path, 'reference');
    $attachment = $resource->attachments()->create(['kind' => 'file', 'path' => $path, 'original_name' => 'reference.pdf']);
    $childEntry = app(TrashService::class)->delete($user, $attachment, 'resource_attachment', 'reference.pdf', $resource->title);
    $entry = app(TrashService::class)->delete($user, $resource, 'resource', $resource->title);
    Passport::actingAs($user);
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.total', 0);
    $revisionKey = 'api-read:revision:user:'.$user->uuid;
    $revision = Cache::store('api_read_revisions')->get($revisionKey);
    $mock = Mockery::mock(FilesystemAdapter::class);
    $mock->shouldReceive('delete')->once()->with($path)->andReturn(false);
    Storage::set('local', $mock);

    try {
        $this->deleteJson(route('trash.destroy', $entry))->assertServerError();
    } finally {
        Storage::set('local', $disk);
    }

    $this->assertSoftDeleted($resource);
    $this->assertSoftDeleted($attachment);
    $this->assertModelExists($entry);
    $this->assertModelExists($childEntry);
    $this->assertSame(1, ResourceAttachment::withTrashed()->where('resource_id', $resource->id)->count());
    $this->assertSame($revision, Cache::store('api_read_revisions')->get($revisionKey));
    $this->assertDatabaseCount('jobs', 0);
    $disk->assertExists($path);
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.total', 0);
});

it('preserves cached views and recoverable files after quota denial then refreshes them when capacity is freed', function (string $store): void {
    config([
        'cache.api_reads.store' => $store, 'cache.api_reads.enabled' => true,
        'plans.limits.free.resources' => 1,
    ]);
    Storage::fake('local');
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Research']);
    $project = $user->projects()->create(['name' => 'Research project']);
    $resource = $user->resources()->create(['title' => 'Original reference']);
    $resource->areas()->attach($area);
    $resource->projects()->attach($project);
    $paths = ["resources/{$resource->uuid}/active.pdf", "resources/{$resource->uuid}/trashed.pdf"];
    foreach ($paths as $path) {
        Storage::disk('local')->put($path, 'reference');
    }
    $attachment = $resource->attachments()->create(['kind' => 'file', 'path' => $paths[0], 'original_name' => 'active.pdf']);
    $trashedAttachment = $resource->attachments()->create(['kind' => 'file', 'path' => $paths[1], 'original_name' => 'trashed.pdf']);
    $childEntry = app(TrashService::class)->delete($user, $trashedAttachment, 'resource_attachment', 'trashed.pdf', $resource->title);
    $entry = app(TrashService::class)->delete($user, $resource, 'resource', $resource->title);
    $replacement = $user->resources()->create(['title' => 'Current reference']);
    $replacement->areas()->attach($area);
    $replacement->projects()->attach($project);
    Passport::actingAs($user);
    $trashUrl = route('trash.index', ['type' => 'resource', 'search' => $resource->title]);
    $resourceList = $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.data.0.uuid', $replacement->uuid);
    $areaList = $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.data.0.uuid', $replacement->uuid);
    $projectView = $this->getJson(route('project.show', $project))->assertOk()->assertJsonPath('data.resources.0.uuid', $replacement->uuid);
    $this->getJson($trashUrl)->assertOk()->assertJsonPath('data.data.0.can_restore', false)
        ->assertJsonPath('data.data.0.restore_block_reason', 'The resources limit for your current plan has been reached.');
    $revisionKey = 'api-read:revision:user:'.$user->uuid;
    $revision = Cache::store('api_read_revisions')->get($revisionKey);
    $resourceState = $resource->fresh()->getRawOriginal();
    $trashState = $entry->fresh()->getRawOriginal();
    $childTrashState = $childEntry->fresh()->getRawOriginal();
    $attachmentState = $attachment->fresh()->getRawOriginal();
    $trashedAttachmentState = $trashedAttachment->fresh()->getRawOriginal();

    $this->postJson(route('trash.restore', $entry))->assertForbidden()
        ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED')
        ->assertJsonPath('meta', ['feature' => 'resources', 'usage' => 1, 'limit' => 1]);

    $this->assertSame($resourceState, $resource->fresh()->getRawOriginal());
    $this->assertSame($trashState, $entry->fresh()->getRawOriginal());
    $this->assertSame($childTrashState, $childEntry->fresh()->getRawOriginal());
    $this->assertSame($attachmentState, $attachment->fresh()->getRawOriginal());
    $this->assertSame($trashedAttachmentState, $trashedAttachment->fresh()->getRawOriginal());
    $this->assertSame($revision, Cache::store('api_read_revisions')->get($revisionKey));
    $this->assertDatabaseCount('jobs', 0);
    Storage::disk('local')->assertExists($paths);
    $this->assertSame($resourceList->getContent(), $this->getJson(route('resource.index'))->assertOk()->getContent());
    $this->assertSame($areaList->getContent(), $this->getJson(route('area.resources.index', $area))->assertOk()->getContent());
    $this->assertSame($projectView->getContent(), $this->getJson(route('project.show', $project))->assertOk()->getContent());

    $this->deleteJson(route('resource.destroy', $replacement))->assertOk();

    $this->getJson($trashUrl)->assertOk()->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.0.restore_block_reason', null);
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonCount(0, 'data.resources');
    $revision = Cache::store('api_read_revisions')->get($revisionKey);

    $this->postJson(route('trash.restore', $entry))->assertOk();

    $this->assertNotSame($revision, Cache::store('api_read_revisions')->get($revisionKey));
    $this->getJson($trashUrl)->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.data.0.uuid', $resource->uuid);
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.data.0.uuid', $resource->uuid);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonPath('data.resources.0.uuid', $resource->uuid);
    $this->assertDatabaseHas('resources', ['id' => $resource->id, 'deleted_at' => null]);
    $this->assertSoftDeleted($replacement);
    $this->assertModelMissing($entry);
    $this->assertModelExists($childEntry);
    $this->assertSoftDeleted($trashedAttachment);
    Storage::disk('local')->assertExists($paths);
})->with(['memory' => ['array'], 'database fallback' => ['database']]);

it('resolves trash restore availability live after plan changes expiry and enforcement changes', function (string $store): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    config(['cache.api_reads.store' => $store, 'cache.api_reads.enabled' => true, 'cache.api_reads.ttl' => 3600]);
    $this->seed(PlanSeeder::class);
    Plan::query()->where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 10, 'areas' => 5, 'resources' => 1]]);
    $focus = Plan::query()->where('slug', 'focus')->sole();
    $focus->updateOrFail(['limits' => ['projects' => 50, 'areas' => 20, 'resources' => 2]]);
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Original reference']);
    $entry = app(TrashService::class)->delete($user, $resource, 'resource', $resource->title);
    $user->resources()->create(['title' => 'Current reference']);
    Passport::actingAs($user);
    $trashUrl = route('trash.index', ['type' => 'resource']);
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson($trashUrl)->assertOk()->assertJsonPath('data.data.0.can_restore', false)
        ->assertJsonPath('data.data.0.restore_block_reason', 'The resources limit for your current plan has been reached.');
    $assignments = app(PlanAssignmentService::class);

    $assignments->assign($user, $focus, PlanGrantType::RECURRING, CarbonImmutable::now()->addMonth(), 'restore-upgrade');

    $this->getJson($trashUrl)->assertOk()->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.0.restore_block_reason', null);

    $assignments->revoke($user);

    $this->getJson($trashUrl)->assertOk()->assertJsonPath('data.data.0.can_restore', false)
        ->assertJsonPath('data.data.0.restore_block_reason', 'The resources limit for your current plan has been reached.');
    $assignments->assign($user, $focus, PlanGrantType::RECURRING, CarbonImmutable::now()->addSeconds(2), 'restore-expiry');
    $this->getJson($trashUrl)->assertOk()->assertJsonPath('data.data.0.can_restore', true);
    $revisionKey = 'api-read:revision:user:'.$user->uuid;
    $revision = Cache::store('api_read_revisions')->get($revisionKey);

    $this->travel(2)->seconds();

    $this->getJson($trashUrl)->assertOk()->assertJsonPath('data.data.0.can_restore', false)
        ->assertJsonPath('data.data.0.restore_block_reason', 'The resources limit for your current plan has been reached.');
    $this->assertSame($revision, Cache::store('api_read_revisions')->get($revisionKey));

    config(['plans.enforcement_enabled' => false]);

    $this->getJson($trashUrl)->assertOk()->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.0.restore_block_reason', null);
    $this->assertSame($revision, Cache::store('api_read_revisions')->get($revisionKey));

    config(['plans.enforcement_enabled' => true]);

    $this->getJson($trashUrl)->assertOk()->assertJsonPath('data.data.0.can_restore', false)
        ->assertJsonPath('data.data.0.restore_block_reason', 'The resources limit for your current plan has been reached.');
    $this->assertSame($revision, Cache::store('api_read_revisions')->get($revisionKey));
    $this->assertSoftDeleted($resource);
    $this->assertModelExists($entry);
})->with(['memory' => ['array'], 'database fallback' => ['database']]);
