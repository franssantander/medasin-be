<?php

namespace Tests\Feature\Trash;

use App\Models\TrashEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TrashLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleted_content_is_listed_and_can_be_restored_by_its_owner(): void
    {
        Carbon::setTestNow('2026-09-05 12:00:00');
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Health']);
        Passport::actingAs($user);

        $this->deleteJson(route('area.destroy', $area))
            ->assertOk()
            ->assertJsonPath('message', 'Area moved to Trash. It will be permanently deleted after 30 days.');

        $entryUuid = $this->getJson(route('trash.index'))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.type', 'area')
            ->assertJsonPath('data.data.0.title', 'Health')
            ->assertJsonPath('data.data.0.days_remaining', 30)
            ->json('data.data.0.uuid');

        $this->postJson(route('trash.restore', $entryUuid))->assertOk();

        $this->assertNotSoftDeleted('areas', ['id' => $area->getKey()]);
        $this->assertDatabaseMissing('trash_entries', ['uuid' => $entryUuid]);
    }

    public function test_note_tree_is_one_grouped_entry_and_restores_only_its_batch(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Writing']);
        $root = $area->notes()->create(['title' => 'Draft', 'content' => '']);
        $child = $area->notes()->create(['parent_id' => $root->getKey(), 'title' => 'Outline', 'content' => '']);
        Passport::actingAs($user);

        $this->deleteJson(route('area.notes.destroy', [$area, $root]))->assertOk();
        $entry = TrashEntry::query()->sole();

        $this->getJson(route('trash.index'))
            ->assertOk()
            ->assertJsonPath('data.data.0.group_size', 2);
        $this->postJson(route('trash.restore', $entry))->assertOk();

        $this->assertNotSoftDeleted('notes', ['id' => $root->getKey()]);
        $this->assertNotSoftDeleted('notes', ['id' => $child->getKey()]);
    }

    public function test_child_restore_is_blocked_while_its_parent_is_in_trash(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Work']);
        $goal = $area->goals()->create(['title' => 'Ship']);
        Passport::actingAs($user);

        $this->deleteJson(route('area.goals.destroy', [$area, $goal]))->assertOk();
        $this->deleteJson(route('area.destroy', $area))->assertOk();
        $entry = TrashEntry::query()->where('item_type', 'goal')->firstOrFail();

        $this->getJson(route('trash.index', ['type' => 'goal']))
            ->assertOk()
            ->assertJsonPath('data.data.0.can_restore', false);
        $this->postJson(route('trash.restore', $entry))
            ->assertConflict()
            ->assertJsonPath('message', 'Restore the parent item first before restoring this item.');
    }

    public function test_expired_entries_are_permanently_pruned(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00');
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Temporary']);
        Passport::actingAs($user);
        $this->deleteJson(route('area.destroy', $area))->assertOk();

        Carbon::setTestNow('2026-08-31 12:00:00');
        $this->artisan('trash:prune')->assertSuccessful();

        $this->assertDatabaseMissing('areas', ['id' => $area->getKey()]);
        $this->assertDatabaseCount('trash_entries', 0);
    }

    public function test_resource_attachment_file_is_retained_and_restored(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $resource = $user->resources()->create(['title' => 'Research']);
        Storage::disk('local')->put('resources/report.pdf', 'content');
        $attachment = $resource->attachments()->create([
            'kind' => 'file',
            'path' => 'resources/report.pdf',
            'original_name' => 'report.pdf',
        ]);
        Passport::actingAs($user);

        $this->deleteJson(route('resource.attachments.destroy', [$resource, $attachment->uuid]))->assertOk();
        $this->assertSoftDeleted('resource_attachments', ['id' => $attachment->getKey()]);
        Storage::disk('local')->assertExists('resources/report.pdf');

        $entry = TrashEntry::query()->where('item_type', 'resource_attachment')->sole();
        $this->postJson(route('trash.restore', $entry))->assertOk();

        $this->assertNotSoftDeleted('resource_attachments', ['id' => $attachment->getKey()]);
        Storage::disk('local')->assertExists('resources/report.pdf');
    }

    public function test_trash_entries_are_private(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $area = $owner->areas()->create(['name' => 'Private']);
        Passport::actingAs($owner);
        $this->deleteJson(route('area.destroy', $area))->assertOk();
        $entry = TrashEntry::query()->sole();

        Passport::actingAs($other);
        $this->getJson(route('trash.index'))->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson(route('trash.restore', $entry))->assertNotFound();
        $this->deleteJson(route('trash.destroy', $entry))->assertNotFound();
    }
}
