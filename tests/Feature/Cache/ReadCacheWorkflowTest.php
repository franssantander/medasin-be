<?php

use App\Jobs\Profile\PurgeAccountFiles;
use App\Models\ResourceAttachment;
use App\Models\TrashEntry;
use App\Models\User;
use App\Services\Trash\TrashService;
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
