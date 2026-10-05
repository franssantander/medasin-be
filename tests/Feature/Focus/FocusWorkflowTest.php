<?php

use App\Models\FocusSession;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('releases a paused session on cancellation and permits a new session', function (): void {
    $this->travelTo('2026-10-05T08:00:00Z');
    $user = User::factory()->create();
    Passport::actingAs($user);
    $uuid = $this->postJson(route('focus.sessions.store'), ['type' => 'short_break'])->assertCreated()->json('data.uuid');
    $session = FocusSession::where('uuid', $uuid)->sole();
    $this->travel(30)->seconds();

    $this->postJson(route('focus.sessions.pause', $session))->assertOk()->assertJsonPath('data.remaining_seconds', 270);
    $this->postJson(route('focus.sessions.cancel', $session))->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson(route('focus.sessions.resume', $session))->assertConflict();
    $this->postJson(route('focus.sessions.store'), ['type' => 'long_break'])
        ->assertCreated()->assertJsonPath('data.status', 'running')->assertJsonPath('data.remaining_seconds', 900);

    $this->assertDatabaseHas('focus_sessions', ['id' => $session->id, 'status' => 'cancelled', 'ends_at' => null, 'paused_at' => null]);
    $this->assertDatabaseCount('focus_sessions', 2);
    $this->assertDatabaseEmpty('journal_entries');
});

it('persists a completed focus reflection as an editable journal entry', function (): void {
    $this->travelTo('2026-10-05T08:00:00Z');
    $user = User::factory()->create();
    Passport::actingAs($user);
    $taskUuid = $this->postJson(route('focus.tasks.store'), ['title' => 'Read a chapter'])->assertCreated()->json('data.uuid');
    $sessionUuid = $this->postJson(route('focus.sessions.store'), ['type' => 'focus', 'focus_task_uuid' => $taskUuid])
        ->assertCreated()->json('data.uuid');
    $session = FocusSession::where('uuid', $sessionUuid)->sole();
    $this->travel(26)->minutes();

    $this->postJson(route('focus.sessions.complete', $session))->assertOk();
    $this->putJson(route('focus.sessions.reflection', $session), ['mood' => 'calm', 'note' => 'Understood the chapter.'])->assertOk();
    $journal = JournalEntry::where('focus_session_id', $session->id)->sole();
    $this->patchJson(route('journal.update', $journal), ['title' => 'Learning notes'])->assertOk();
    $this->getJson(route('journal.show', $journal))->assertOk()
        ->assertJsonPath('data.title', 'Learning notes')
        ->assertJsonPath('data.source.session_uuid', $sessionUuid)
        ->assertJsonPath('data.content_preview', 'Understood the chapter.');

    $this->assertDatabaseHas('journal_entries', [
        'id' => $journal->id, 'title' => 'Learning notes', 'focus_session_id' => $session->id,
    ]);
});
