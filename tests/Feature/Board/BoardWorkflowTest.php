<?php

use App\Models\Board;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('reorders moved tasks and preserves their label links', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);
    $uuid = $this->postJson(route('board.store'), ['name' => 'Reading'])->assertCreated()->json('data.uuid');
    $board = Board::where('uuid', $uuid)->sole();
    $labelUuid = $this->postJson(route('board.labels.store', $board), ['name' => 'Learning', 'color' => 'blue'])
        ->assertCreated()->json('data.uuid');
    $firstUuid = $this->postJson(route('board.tasks.store', $board), [
        'title' => 'First task', 'label_uuids' => [$labelUuid],
    ])->assertCreated()->json('data.uuid');
    $secondUuid = $this->postJson(route('board.tasks.store', $board), ['title' => 'Second task'])
        ->assertCreated()->json('data.uuid');
    $first = $board->tasks()->where('uuid', $firstUuid)->sole();
    $second = $board->tasks()->where('uuid', $secondUuid)->sole();

    $this->patchJson(route('board.tasks.move', [$board, $second]), ['stage' => 'backlog', 'position' => 0])->assertOk();
    $this->patchJson(route('board.tasks.move', [$board, $first]), ['stage' => 'done', 'position' => 0])
        ->assertOk()->assertJsonPath('data.labels.0.uuid', $labelUuid);
    $this->getJson(route('board.show', $board))->assertOk()
        ->assertJsonCount(1, 'data.stages.0.tasks')
        ->assertJsonPath('data.stages.0.tasks.0.uuid', $secondUuid)
        ->assertJsonPath('data.stages.0.tasks.0.position', 0)
        ->assertJsonPath('data.stages.3.tasks.0.uuid', $firstUuid);

    $this->assertDatabaseHas('board_tasks', ['id' => $first->id, 'board_stage_id' => $board->stages()->where('key', 'done')->value('id')]);
    $this->assertDatabaseHas('board_label_task', ['board_task_id' => $first->id, 'board_label_id' => $board->labels()->where('uuid', $labelUuid)->value('id')]);
});
