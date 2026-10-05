<?php

use App\Models\TrashEntry;
use App\Models\User;
use App\Services\Board\BoardService;
use App\Services\Board\BoardTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
