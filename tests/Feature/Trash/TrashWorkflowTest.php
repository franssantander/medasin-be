<?php

use App\Models\Resource;
use App\Models\ResourceTag;
use App\Models\TrashEntry;
use App\Models\User;
use App\Services\Board\BoardService;
use App\Services\Board\BoardTaskService;
use App\Services\Trash\TrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('restores a board task with its original stage and resource links', function (): void {
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Reference']);
    $board = app(BoardService::class)->createStandalone($user);
    $task = app(BoardTaskService::class)->create($user, $board, [
        'title' => 'Read reference', 'stage' => 'in_progress', 'resource_uuids' => [$resource->uuid],
    ]);
    Passport::actingAs($user);

    $this->deleteJson(route('board.tasks.destroy', [$board, $task]))->assertOk();
    $entry = TrashEntry::where('subject_uuid', $task->uuid)->sole();
    $this->getJson(route('trash.index', ['type' => 'task']))->assertOk()->assertJsonPath('data.total', 1);
    $this->postJson(route('trash.restore', $entry))->assertOk();
    $this->getJson(route('board.show', $board))->assertOk()
        ->assertJsonPath('data.stages.2.tasks.0.uuid', $task->uuid)
        ->assertJsonPath('data.stages.2.tasks.0.resources.0.uuid', $resource->uuid);

    $this->assertDatabaseHas('board_tasks', ['id' => $task->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('board_task_resource', ['board_task_id' => $task->id, 'resource_id' => $resource->id]);
    $this->assertModelMissing($entry);
});

it('returns 410 and removes content when restoring an expired trash entry', function (): void {
    $this->travelTo('2026-10-05T08:00:00Z');
    $user = User::factory()->create();
    $note = $user->standaloneNotes()->create(['title' => 'Expired note', 'content' => 'Content']);
    Passport::actingAs($user);
    $this->deleteJson(route('notes.destroy', $note))->assertOk();
    $entry = TrashEntry::where('subject_uuid', $note->uuid)->sole();
    $this->travel(30)->days();

    $this->postJson(route('trash.restore', $entry))->assertGone()
        ->assertJsonPath('message', 'This item has expired and was permanently deleted.');

    $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    $this->assertModelMissing($entry);
});

it('moves a resource into one 30-day trash entry and restores its attachments and associations', function (): void {
    $this->travelTo('2026-10-05T08:00:00Z');
    Storage::fake('local');
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Research']);
    $project = $user->projects()->create(['name' => 'Launch']);
    Passport::actingAs($user);
    $uuid = $this->postJson(route('resource.store'), [
        'title' => 'Research reference',
        'links' => ['https://example.com/reference'],
        'files' => [UploadedFile::fake()->createWithContent('reference.txt', 'Saved reference content')],
        'tag_names' => ['Research'],
        'area_uuids' => [$area->uuid],
        'project_uuids' => [$project->uuid],
    ])->assertCreated()->json('data.uuid');
    $resource = $user->resources()->where('uuid', $uuid)->sole();
    $file = $resource->attachments()->where('kind', 'file')->sole();
    $link = $resource->attachments()->where('kind', 'link')->sole();
    $tag = $resource->tags()->sole();
    $note = $user->standaloneNotes()->create(['title' => 'Separate note', 'content' => '']);
    $this->deleteJson(route('notes.destroy', $note))->assertOk();

    $this->deleteJson(route('resource.destroy', $resource))->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('message', 'Resource moved to Trash. It will be permanently deleted after 30 days.');

    $entry = TrashEntry::query()->where('subject_uuid', $resource->uuid)->sole();
    $this->assertSoftDeleted($resource);
    $this->assertDatabaseHas('trash_entries', [
        'id' => $entry->id, 'user_id' => $user->id, 'subject_type' => Resource::class,
        'subject_id' => $resource->id, 'item_type' => 'resource',
        'deleted_at' => '2026-10-05 08:00:00', 'expires_at' => '2026-11-04 08:00:00',
    ]);
    $this->assertNotSoftDeleted($file);
    $this->assertNotSoftDeleted($link);
    $this->assertDatabaseHas('area_resource', ['area_id' => $area->id, 'resource_id' => $resource->id]);
    $this->assertDatabaseHas('project_resource', ['project_id' => $project->id, 'resource_id' => $resource->id]);
    $this->assertDatabaseHas('resource_resource_tag', ['resource_tag_id' => $tag->id, 'resource_id' => $resource->id]);
    Storage::disk('local')->assertExists($file->path);
    $this->getJson(route('resource.show', $resource))->assertNotFound();
    $this->getJson(route('resource.attachments.show', [$resource, $file->uuid]))->assertNotFound();
    $this->getJson(route('trash.index', ['type' => 'resource']))->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.uuid', $entry->uuid)
        ->assertJsonPath('data.data.0.type', 'resource')
        ->assertJsonPath('data.data.0.title', 'Research reference')
        ->assertJsonPath('data.data.0.group_size', 1)
        ->assertJsonPath('data.data.0.days_remaining', 30)
        ->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.0.expires_at', '2026-11-04T08:00:00.000000Z');

    $this->postJson(route('trash.restore', $entry))->assertOk()
        ->assertJsonPath('message', 'Successfully restored Research reference.');

    $this->assertNotSoftDeleted($resource);
    $this->assertModelMissing($entry);
    $this->getJson(route('resource.show', $resource))->assertOk()
        ->assertJsonCount(2, 'data.attachments')
        ->assertJsonPath('data.areas.0.uuid', $area->uuid)
        ->assertJsonPath('data.projects.0.uuid', $project->uuid)
        ->assertJsonPath('data.tags.0.uuid', $tag->uuid)
        ->assertJsonFragment(['uuid' => $link->uuid, 'kind' => 'link', 'url' => 'https://example.com/reference']);
    $this->getJson(route('resource.attachments.show', [$resource, $file->uuid]))
        ->assertOk()->assertDownload('reference.txt');
    Storage::disk('local')->assertExists($file->path);
    expect(Storage::disk('local')->get($file->path))->toBe('Saved reference content');
    $this->getJson(route('trash.index', ['type' => 'resource']))->assertOk()->assertJsonPath('data.total', 0);
});

it('allows an archived resource to enter trash and preserves its archive state on recovery', function (): void {
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Archived reference', 'archived_at' => '2026-09-01 12:00:00']);
    Passport::actingAs($user);

    $this->deleteJson(route('resource.destroy', $resource))->assertOk();

    $this->assertSoftDeleted($resource);
    $entry = TrashEntry::query()->where('subject_uuid', $resource->uuid)->sole();
    $this->postJson(route('trash.restore', $entry))->assertOk();
    $this->assertDatabaseHas('resources', ['id' => $resource->id, 'deleted_at' => null, 'archived_at' => '2026-09-01 12:00:00']);
    $this->assertModelMissing($entry);
    $this->getJson(route('resource.index', ['status' => 'archived']))->assertOk()
        ->assertJsonPath('data.data.0.uuid', $resource->uuid);
});

it('returns 401 without moving a resource to trash for a guest', function (): void {
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Private reference']);

    $this->deleteJson(route('resource.destroy', $resource))->assertUnauthorized();

    $this->assertNotSoftDeleted($resource);
    $this->assertDatabaseCount('trash_entries', 0);
});

it('returns 403 without moving a resource to trash for an unverified owner', function (): void {
    $user = User::factory()->unverified()->create();
    $resource = $user->resources()->create(['title' => 'Private reference']);
    Passport::actingAs($user);

    $this->deleteJson(route('resource.destroy', $resource))->assertForbidden()
        ->assertJsonPath('message', 'Please verify your email address before accessing the app.');

    $this->assertNotSoftDeleted($resource);
    $this->assertDatabaseCount('trash_entries', 0);
});

it('returns 404 without changing another owners resource or attachment files', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $resource = $owner->resources()->create(['title' => 'Private reference']);
    Storage::disk('local')->put('resources/private.txt', 'Private content');
    $attachment = $resource->attachments()->create(['kind' => 'file', 'path' => 'resources/private.txt']);
    Passport::actingAs(User::factory()->create());

    $this->deleteJson(route('resource.destroy', $resource))->assertNotFound();

    $this->assertDatabaseHas('resources', ['id' => $resource->id, 'user_id' => $owner->id, 'deleted_at' => null]);
    $this->assertNotSoftDeleted($attachment);
    $this->assertDatabaseCount('trash_entries', 0);
    Storage::disk('local')->assertExists('resources/private.txt');
});

it('keeps resource trash listing restore and purge private to its owner', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $resource = $owner->resources()->create(['title' => 'Private reference']);
    Storage::disk('local')->put('resources/private.txt', 'Private content');
    $attachment = $resource->attachments()->create(['kind' => 'file', 'path' => 'resources/private.txt']);
    Passport::actingAs($owner);
    $this->deleteJson(route('resource.destroy', $resource))->assertOk();
    $entry = TrashEntry::query()->where('subject_uuid', $resource->uuid)->sole();
    Passport::actingAs(User::factory()->create());

    $this->getJson(route('trash.index', ['type' => 'resource']))->assertOk()->assertJsonPath('data.total', 0);
    $this->postJson(route('trash.restore', $entry))->assertNotFound();
    $this->deleteJson(route('trash.destroy', $entry))->assertNotFound();

    $this->assertSoftDeleted($resource);
    $this->assertNotSoftDeleted($attachment);
    $this->assertModelExists($entry);
    Storage::disk('local')->assertExists('resources/private.txt');
});

it('restores the resource before allowing separately trashed attachment recovery', function (): void {
    Storage::fake('local');
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Research']);
    Storage::disk('local')->put('resources/reference.txt', 'Reference content');
    $attachment = $resource->attachments()->create([
        'kind' => 'file', 'path' => 'resources/reference.txt', 'original_name' => 'reference.txt',
    ]);
    Passport::actingAs($user);
    $this->deleteJson(route('resource.attachments.destroy', [$resource, $attachment->uuid]))->assertOk();
    $attachmentEntry = TrashEntry::query()->where('subject_uuid', $attachment->uuid)->sole();
    $this->deleteJson(route('resource.destroy', $resource))->assertOk();
    $resourceEntry = TrashEntry::query()->where('subject_uuid', $resource->uuid)->sole();

    $this->getJson(route('trash.index', ['type' => 'resource_attachment']))->assertOk()
        ->assertJsonPath('data.data.0.can_restore', false)
        ->assertJsonPath('data.data.0.restore_block_reason', 'Restore the parent item first before restoring this item.');
    $this->postJson(route('trash.restore', $attachmentEntry))->assertConflict()
        ->assertJsonPath('message', 'Restore the parent item first before restoring this item.');

    $this->assertSoftDeleted($attachment);
    $this->assertModelExists($attachmentEntry);
    $this->postJson(route('trash.restore', $resourceEntry))->assertOk();
    $this->assertNotSoftDeleted($resource);
    $this->assertSoftDeleted($attachment);
    $this->assertModelExists($attachmentEntry);
    $this->getJson(route('resource.show', $resource))->assertOk()->assertJsonCount(0, 'data.attachments');
    $this->getJson(route('trash.index', ['type' => 'resource_attachment']))->assertOk()
        ->assertJsonPath('data.data.0.can_restore', true);

    $this->postJson(route('trash.restore', $attachmentEntry))->assertOk();

    $this->assertNotSoftDeleted($attachment);
    $this->assertModelMissing($attachmentEntry);
    $this->getJson(route('resource.attachments.show', [$resource, $attachment->uuid]))
        ->assertOk()->assertDownload('reference.txt');
    Storage::disk('local')->assertExists('resources/reference.txt');
});

it('purges resource files and child trash without removing shared tags or unrelated data', function (bool $prune): void {
    $this->travelTo('2026-09-01T08:00:00Z');
    Storage::fake('local');
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Temporary reference']);
    $file = $resource->attachments()->create(['kind' => 'file', 'path' => 'resources/active.txt']);
    $trashedFile = $resource->attachments()->create(['kind' => 'file', 'path' => 'resources/trashed.txt']);
    $link = $resource->attachments()->create(['kind' => 'link', 'url' => 'https://example.com/reference']);
    $otherResource = $user->resources()->create(['title' => 'Keep reference']);
    $otherFile = $otherResource->attachments()->create(['kind' => 'file', 'path' => 'resources/keep.txt']);
    $tag = ResourceTag::create(['user_id' => $user->id, 'name' => 'Shared', 'normalized_name' => 'shared']);
    $resource->tags()->attach($tag);
    $otherResource->tags()->attach($tag);
    $otherOwner = User::factory()->create();
    $privateResource = $otherOwner->resources()->create(['title' => 'Private reference']);
    $privateFile = $privateResource->attachments()->create(['kind' => 'file', 'path' => 'resources/private.txt']);
    $privateTag = ResourceTag::create(['user_id' => $otherOwner->id, 'name' => 'Private', 'normalized_name' => 'private']);
    $privateResource->tags()->attach($privateTag);
    foreach (['resources/active.txt', 'resources/trashed.txt', 'resources/keep.txt', 'resources/private.txt'] as $path) {
        Storage::disk('local')->put($path, 'Saved content');
    }
    Passport::actingAs($user);
    $this->deleteJson(route('resource.attachments.destroy', [$resource, $trashedFile->uuid]))->assertOk();
    $attachmentEntry = TrashEntry::query()->where('subject_uuid', $trashedFile->uuid)->sole();
    $this->deleteJson(route('resource.destroy', $resource))->assertOk();
    $resourceEntry = TrashEntry::query()->where('subject_uuid', $resource->uuid)->sole();
    $this->travel(1)->day();
    $this->deleteJson(route('resource.attachments.destroy', [$otherResource, $otherFile->uuid]))->assertOk();
    $otherEntry = TrashEntry::query()->where('subject_uuid', $otherFile->uuid)->sole();
    Passport::actingAs($otherOwner);
    $this->deleteJson(route('resource.destroy', $privateResource))->assertOk();
    $privateEntry = TrashEntry::query()->where('subject_uuid', $privateResource->uuid)->sole();
    Passport::actingAs($user);

    if ($prune) {
        $this->travel(29)->days();
        $this->artisan('trash:prune')->assertSuccessful();
    } else {
        $this->deleteJson(route('trash.destroy', $resourceEntry))->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'Permanently deleted Temporary reference.');
    }

    $this->assertDatabaseMissing('resources', ['id' => $resource->id]);
    $this->assertModelMissing($file);
    $this->assertModelMissing($trashedFile);
    $this->assertModelMissing($link);
    $this->assertModelMissing($resourceEntry);
    $this->assertModelMissing($attachmentEntry);
    $this->assertDatabaseMissing('resource_resource_tag', ['resource_id' => $resource->id]);
    Storage::disk('local')->assertMissing(['resources/active.txt', 'resources/trashed.txt']);
    $this->assertNotSoftDeleted($otherResource);
    $this->assertSoftDeleted($otherFile);
    $this->assertModelExists($otherEntry);
    $this->assertSoftDeleted($privateResource);
    $this->assertNotSoftDeleted($privateFile);
    $this->assertModelExists($privateEntry);
    $this->assertModelExists($tag);
    $this->assertModelExists($privateTag);
    $this->assertDatabaseHas('resource_resource_tag', ['resource_id' => $otherResource->id, 'resource_tag_id' => $tag->id]);
    $this->assertDatabaseHas('resource_resource_tag', ['resource_id' => $privateResource->id, 'resource_tag_id' => $privateTag->id]);
    Storage::disk('local')->assertExists(['resources/keep.txt', 'resources/private.txt']);
})->with(['manual purge' => [false], 'expiration prune' => [true]]);

it('continues pruning when a resource purge removed a later attachment entry from the same batch', function (): void {
    $this->travelTo('2026-09-01T08:00:00Z');
    Storage::fake('local');
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Temporary reference']);
    Storage::disk('local')->put('resources/reference.txt', 'Saved content');
    $attachment = $resource->attachments()->create(['kind' => 'file', 'path' => 'resources/reference.txt']);
    Passport::actingAs($user);
    $this->deleteJson(route('resource.destroy', $resource))->assertOk();
    app(TrashService::class)->delete($user, $attachment, 'resource_attachment', 'reference.txt', $resource->title);
    $otherResource = $user->resources()->create(['title' => 'Another expired reference']);
    $this->deleteJson(route('resource.destroy', $otherResource))->assertOk();
    $this->travel(30)->days();

    $this->artisan('trash:prune')->assertSuccessful();

    $this->assertDatabaseMissing('resources', ['id' => $resource->id]);
    $this->assertDatabaseMissing('resources', ['id' => $otherResource->id]);
    $this->assertModelMissing($attachment);
    $this->assertDatabaseCount('trash_entries', 0);
    Storage::disk('local')->assertMissing('resources/reference.txt');
});
