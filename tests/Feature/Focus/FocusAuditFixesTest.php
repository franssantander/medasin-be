<?php

use App\Models\FocusSession;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

function startedFocusSession(): FocusSession
{
    $taskUuid = test()->postJson(route('focus.tasks.store'), ['title' => 'Draft the essay'])->json('data.uuid');
    $uuid = test()->postJson(route('focus.sessions.store'), ['type' => 'focus', 'focus_task_uuid' => $taskUuid])
        ->assertCreated()->json('data.uuid');

    return FocusSession::where('uuid', $uuid)->sole();
}

it('dates a session that ran out unattended to when its timer ended', function (): void {
    $this->travelTo('2026-10-08T22:00:00Z');
    Passport::actingAs(User::factory()->create());
    $session = startedFocusSession();
    $endsAt = $session->ends_at->copy();

    $this->travelTo('2026-10-09T08:00:00Z');
    $this->getJson(route('focus.show', ['timezone' => 'UTC']))->assertOk();

    expect($session->fresh()->completed_at->equalTo($endsAt))->toBeTrue();
});

it('dates an explicit late completion to when the timer ended', function (): void {
    $this->travelTo('2026-10-08T22:00:00Z');
    Passport::actingAs(User::factory()->create());
    $session = startedFocusSession();
    $endsAt = $session->ends_at->copy();

    $this->travelTo('2026-10-08T23:30:00Z');
    $this->postJson(route('focus.sessions.complete', $session))->assertOk();

    expect($session->fresh()->completed_at->equalTo($endsAt))->toBeTrue();
});

it('treats % and _ in the linkable task search as literal characters', function (): void {
    Passport::actingAs(User::factory()->create());
    $project = Project::where('uuid', $this->postJson(route('project.store'), ['name' => 'Launch'])->json('data.uuid'))->sole();
    $board = $project->boards()->firstOrFail();
    foreach (['Reach 100% coverage', 'Reach 1000 readers'] as $title) {
        $this->postJson(route('project.boards.tasks.store', [$project, $board]), ['title' => $title, 'stage' => 'todos'])->assertCreated();
    }

    $this->getJson(route('focus.linkable-tasks', ['search' => '100%']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Reach 100% coverage');
});

it('leaves the journal entry alone after the reflection first creates it', function (): void {
    $this->travelTo('2026-10-08T08:00:00Z');
    Passport::actingAs(User::factory()->create());
    $session = startedFocusSession();
    Carbon::setTestNow(now()->addHour());
    $this->postJson(route('focus.sessions.complete', $session))->assertOk();

    $this->putJson(route('focus.sessions.reflection', $session), ['mood' => 'calm', 'note' => 'Good momentum.'])->assertOk();
    $entry = JournalEntry::query()->where('focus_session_id', $session->id)->sole();
    $entry->forceFill(['title' => 'My own title'])->save();

    $this->putJson(route('focus.sessions.reflection', $session), ['mood' => 'tired', 'note' => 'Changed my mind.'])->assertOk();
    expect($entry->fresh())->title->toBe('My own title')->content_text->toBe('Good momentum.');

    $this->deleteJson(route('journal.destroy', $entry->uuid))->assertOk();
    $this->putJson(route('focus.sessions.reflection', $session), ['mood' => 'calm', 'note' => 'Once more.'])->assertOk();

    expect($entry->fresh()->trashed())->toBeTrue()
        ->and(JournalEntry::query()->count())->toBe(0);
});
