<?php

namespace Tests\Feature\Journal;

use App\Models\JournalEntry;
use App\Models\TrashEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class JournalModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_journal_requests_return_401(): void
    {
        $this->getJson(route('journal.index'))->assertUnauthorized();
        $this->postJson(route('journal.store'))->assertUnauthorized();
    }

    public function test_valid_entry_creates_rich_content_and_owned_resource_links(): void
    {
        $user = User::factory()->create();
        $resource = $user->resources()->create(['title' => 'Research reference', 'type' => 'link']);
        $content = '{"version":1,"blocks":[{"type":"paragraph","content":"A useful reflection."}]}';
        Passport::actingAs($user);

        $response = $this->postJson(route('journal.store'), [
            'title' => 'Today\'s reflection',
            'content' => $content,
            'resource_uuids' => [$resource->uuid],
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Today\'s reflection')
            ->assertJsonPath('data.content', $content)
            ->assertJsonPath('data.content_preview', 'A useful reflection.')
            ->assertJsonPath('data.resources.0.uuid', $resource->uuid)
            ->assertJsonPath('data.resources.0.title', 'Research reference')
            ->assertJsonPath('data.source', null);

        $entry = JournalEntry::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertModelExists($entry);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->getKey(),
            'user_id' => $user->getKey(),
            'content_text' => 'A useful reflection.',
        ]);
        $this->assertDatabaseHas('journal_entry_resource', [
            'journal_entry_id' => $entry->getKey(),
            'resource_id' => $resource->getKey(),
        ]);
    }

    public function test_listing_is_paginated_newest_first_and_scoped_to_active_owned_entries(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $older = JournalEntry::factory()->for($user)->create([
            'title' => 'Older reflection',
            'updated_at' => '2026-09-09 12:00:00',
        ]);
        $newer = JournalEntry::factory()->for($user)->create([
            'title' => 'Newer reflection',
            'content_text' => 'The newest entry.',
            'updated_at' => '2026-09-10 12:00:00',
        ]);
        JournalEntry::factory()->for($user)->create(['title' => 'Deleted reflection'])->delete();
        JournalEntry::factory()->for($otherUser)->create(['title' => 'Private reflection']);
        Passport::actingAs($user);

        $this->getJson(route('journal.index', ['per_page' => 1]))
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.uuid', $newer->uuid)
            ->assertJsonPath('data.data.0.title', 'Newer reflection')
            ->assertJsonPath('data.data.0.content_preview', 'The newest entry.')
            ->assertJsonMissingPath('data.data.0.content');

        $this->getJson(route('journal.index', ['per_page' => 100]))
            ->assertOk()
            ->assertJsonPath('data.data.1.uuid', $older->uuid);
    }

    public function test_update_replaces_links_and_preserves_them_when_the_field_is_omitted(): void
    {
        $user = User::factory()->create();
        $oldResource = $user->resources()->create(['title' => 'Old reference']);
        $newResource = $user->resources()->create(['title' => 'New reference']);
        $entry = JournalEntry::factory()->for($user)->create(['title' => 'Draft']);
        $entry->resources()->attach($oldResource);
        Passport::actingAs($user);

        $content = '{"version":1,"blocks":[{"type":"paragraph","content":"Updated reflection."}]}';
        $this->patchJson(route('journal.update', $entry->uuid), [
            'title' => 'Updated draft',
            'content' => $content,
            'resource_uuids' => [$newResource->uuid],
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated draft')
            ->assertJsonPath('data.content', $content)
            ->assertJsonPath('data.resources.0.uuid', $newResource->uuid);

        $this->assertDatabaseMissing('journal_entry_resource', [
            'journal_entry_id' => $entry->getKey(),
            'resource_id' => $oldResource->getKey(),
        ]);
        $this->assertDatabaseHas('journal_entry_resource', [
            'journal_entry_id' => $entry->getKey(),
            'resource_id' => $newResource->getKey(),
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->getKey(),
            'content_text' => 'Updated reflection.',
        ]);

        $this->patchJson(route('journal.update', $entry->uuid), ['title' => 'Renamed draft'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed draft')
            ->assertJsonPath('data.resources.0.uuid', $newResource->uuid);

        $this->patchJson(route('journal.update', $entry->uuid), ['resource_uuids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.resources');
    }

    public function test_invalid_content_and_foreign_or_archived_resources_return_422(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreignResource = $otherUser->resources()->create(['title' => 'Private reference']);
        $archivedResource = $user->resources()->create(['title' => 'Archived reference', 'archived_at' => now()]);
        Passport::actingAs($user);

        $this->postJson(route('journal.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'content']);
        $this->postJson(route('journal.store'), [
            'title' => 'Invalid',
            'content' => '{"version":2,"blocks":[]}',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
        $this->postJson(route('journal.store'), [
            'title' => 'Invalid links',
            'content' => '{"version":1,"blocks":[]}',
            'resource_uuids' => [$foreignResource->uuid, $archivedResource->uuid],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resource_uuids.0', 'resource_uuids.1']);

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_users_cannot_read_update_or_delete_another_users_entry(): void
    {
        $owner = User::factory()->create();
        $entry = JournalEntry::factory()->for($owner)->create();
        Passport::actingAs(User::factory()->create());

        $this->getJson(route('journal.show', $entry->uuid))->assertNotFound();
        $this->patchJson(route('journal.update', $entry->uuid), ['title' => 'No access'])->assertNotFound();
        $this->deleteJson(route('journal.destroy', $entry->uuid))->assertNotFound();

        $this->assertModelExists($entry);
    }

    public function test_deleting_a_journal_entry_uses_trash_and_restores_resource_links(): void
    {
        $user = User::factory()->create();
        $resource = $user->resources()->create(['title' => 'Reference']);
        $entry = JournalEntry::factory()->for($user)->create(['title' => 'Disposable reflection']);
        $entry->resources()->attach($resource);
        Passport::actingAs($user);

        $this->deleteJson(route('journal.destroy', $entry->uuid))
            ->assertOk()
            ->assertJsonPath('message', 'Journal entry moved to Trash. It will be permanently deleted after 30 days.');

        $this->assertSoftDeleted('journal_entries', ['uuid' => $entry->uuid]);
        $trashEntry = TrashEntry::query()->where('subject_type', JournalEntry::class)->sole();
        $this->assertSame('journal_entry', $trashEntry->item_type);

        $this->postJson(route('trash.restore', $trashEntry->uuid))->assertOk();

        $this->assertNotSoftDeleted('journal_entries', ['uuid' => $entry->uuid]);
        $this->assertDatabaseHas('journal_entry_resource', [
            'journal_entry_id' => $entry->getKey(),
            'resource_id' => $resource->getKey(),
        ]);
    }
}
