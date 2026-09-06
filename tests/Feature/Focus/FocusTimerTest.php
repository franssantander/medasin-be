<?php

namespace Tests\Feature\Focus;

use App\Models\FocusSession;
use App\Models\FocusTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Tests\TestCase;

class FocusTimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_focus_dashboard_creates_defaults_and_manages_standalone_tasks(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->getJson(route('focus.show', ['timezone' => 'Asia/Manila']))
            ->assertOk()
            ->assertJsonPath('data.settings.focus_minutes', 25)
            ->assertJsonPath('data.settings.ask_before_next_session', true)
            ->assertJsonPath('data.today.completed_focus_sessions', 0);

        $uuid = $this->postJson(route('focus.tasks.store'), ['title' => 'Read Deep Work chapter 3'])
            ->assertCreated()
            ->assertJsonPath('data.linked', false)
            ->json('data.uuid');

        $this->patchJson(route('focus.tasks.update', FocusTask::where('uuid', $uuid)->firstOrFail()), ['completed' => true])
            ->assertOk()->assertJsonPath('data.completed_at', fn ($value) => $value !== null);
        $this->getJson(route('focus.tasks.index', ['status' => 'completed']))->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson(route('focus.tasks.update', FocusTask::where('uuid', $uuid)->firstOrFail()), ['completed' => false])
            ->assertOk()->assertJsonPath('data.completed_at', null);
    }

    public function test_sessions_are_server_resumable_and_only_one_can_be_active(): void
    {
        Carbon::setTestNow('2026-09-06 00:00:00');
        $user = User::factory()->create();
        Passport::actingAs($user);
        $taskUuid = $this->postJson(route('focus.tasks.store'), ['title' => 'Draft notes'])->json('data.uuid');
        $this->putJson(route('focus.settings.update'), [
            'focus_minutes' => 1,
            'short_break_minutes' => 1,
            'long_break_minutes' => 2,
            'sessions_before_long_break' => 2,
            'ask_before_next_session' => true,
            'ask_for_reflection' => true,
            'ambient_sound' => 'brown',
        ])->assertOk();

        $sessionUuid = $this->postJson(route('focus.sessions.store'), ['type' => 'focus', 'focus_task_uuid' => $taskUuid])
            ->assertCreated()
            ->assertJsonPath('data.remaining_seconds', 60)
            ->assertJsonPath('data.server_now', fn ($value) => is_string($value))
            ->json('data.uuid');
        $this->postJson(route('focus.sessions.store'), ['type' => 'short_break'])->assertConflict();

        Carbon::setTestNow('2026-09-06 00:00:10.250000');
        $session = FocusSession::where('uuid', $sessionUuid)->firstOrFail();
        $this->postJson(route('focus.sessions.pause', $session))->assertOk()->assertJsonPath('data.remaining_seconds', 50);
        Carbon::setTestNow('2026-09-06 00:00:20');
        $this->postJson(route('focus.sessions.resume', $session))->assertOk()->assertJsonPath('data.status', 'running');

        Carbon::setTestNow('2026-09-06 00:01:11');
        $this->getJson(route('focus.show', ['timezone' => 'UTC']))
            ->assertOk()
            ->assertJsonPath('data.active_session', null)
            ->assertJsonPath('data.today.completed_focus_sessions', 1)
            ->assertJsonPath('data.today.focused_seconds', 60)
            ->assertJsonPath('data.suggested_next_type', 'short_break');

        $session->refresh();
        $this->putJson(route('focus.sessions.reflection', $session), ['mood' => 'calm', 'note' => 'Good momentum.'])
            ->assertOk()->assertJsonPath('data.mood', 'calm')->assertJsonPath('data.reflection_note', 'Good momentum.');
    }

    public function test_project_tasks_can_be_linked_without_changing_the_board_task_stage(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        $projectUuid = $this->postJson(route('project.store'), ['name' => 'Launch', 'description' => null])->assertCreated()->json('data.uuid');
        $project = $user->projects()->where('uuid', $projectUuid)->firstOrFail();
        $board = $project->boards()->firstOrFail();
        $boardTaskUuid = $this->postJson(route('project.boards.tasks.store', [$project, $board]), ['title' => 'Draft launch brief', 'stage' => 'in_progress'])
            ->assertCreated()->json('data.uuid');

        $this->getJson(route('focus.linkable-tasks'))->assertOk()->assertJsonPath('data.0.uuid', $boardTaskUuid);
        $focusTaskUuid = $this->postJson(route('focus.tasks.store'), ['board_task_uuid' => $boardTaskUuid])
            ->assertCreated()->assertJsonPath('data.linked', true)->json('data.uuid');
        $this->patchJson(route('focus.tasks.update', FocusTask::where('uuid', $focusTaskUuid)->firstOrFail()), ['completed' => true])->assertOk();

        $this->assertDatabaseHas('board_tasks', ['uuid' => $boardTaskUuid, 'board_stage_id' => $board->stages()->where('key', 'in_progress')->value('id')]);
    }

    public function test_users_cannot_access_each_others_focus_records(): void
    {
        $owner = User::factory()->create();
        $task = $owner->focusTasks()->create(['title' => 'Private task']);
        Passport::actingAs(User::factory()->create());

        $this->patchJson(route('focus.tasks.update', $task), ['completed' => true])->assertNotFound();
        $this->deleteJson(route('focus.tasks.destroy', $task))->assertNotFound();
    }
}
