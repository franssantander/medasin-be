<?php

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('keeps saved content and resource links after a rejected cross account edit', function (): void {
    $user = User::factory()->create();
    $owned = $user->resources()->create(['title' => 'Owned source']);
    $foreign = User::factory()->create()->resources()->create(['title' => 'Private source']);
    $content = '{"version":1,"blocks":[{"type":"paragraph","content":"Keep this reflection."}]}';
    Passport::actingAs($user);
    $uuid = $this->postJson(route('journal.store'), [
        'title' => 'Reflection', 'content' => $content, 'resource_uuids' => [$owned->uuid],
    ])->assertCreated()->json('data.uuid');
    $entry = JournalEntry::where('uuid', $uuid)->sole();

    $this->patchJson(route('journal.update', $uuid), [
        'title' => 'Rejected edit', 'resource_uuids' => [$foreign->uuid],
    ])->assertUnprocessable()->assertJsonValidationErrors('resource_uuids.0');
    $this->getJson(route('journal.show', $uuid))->assertOk()
        ->assertJsonPath('data.title', 'Reflection')->assertJsonPath('data.content', $content)
        ->assertJsonPath('data.resources.0.uuid', $owned->uuid);

    $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'content_text' => 'Keep this reflection.']);
    $this->assertDatabaseHas('journal_entry_resource', ['journal_entry_id' => $entry->id, 'resource_id' => $owned->id]);
    $this->assertDatabaseMissing('journal_entry_resource', ['journal_entry_id' => $entry->id, 'resource_id' => $foreign->id]);
});
