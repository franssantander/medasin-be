<?php

namespace Tests\Feature\Board;

use App\Models\Board;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class StandaloneBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_receive_one_default_board_and_cannot_delete_the_last_board(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $uuid = $this->getJson(route('board.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Board 1')
            ->json('data.0.uuid');
        $board = Board::where('uuid', $uuid)->firstOrFail();

        $this->getJson(route('board.index'))->assertOk()->assertJsonCount(1, 'data');

        $this->assertNull($board->context_type);
        $this->assertNull($board->context_id);
        $this->assertSame($user->id, $board->user_id);
        $this->assertSame(
            ['backlog', 'todos', 'in_progress', 'done'],
            $board->stages()->get()->map(fn ($stage) => $stage->key->value)->all(),
        );

        $this->putJson(route('board.update', $board), ['name' => 'Personal'])
            ->assertOk()->assertJsonPath('data.name', 'Personal');
        $this->deleteJson(route('board.destroy', $board))
            ->assertConflict()
            ->assertJsonPath('message', 'A standalone board must keep at least one board.');

        $secondUuid = $this->postJson(route('board.store'), [])->assertCreated()->json('data.uuid');
        $this->deleteJson(route('board.destroy', $board))->assertOk();
        $this->getJson(route('board.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $secondUuid);
    }

    public function test_standalone_tasks_support_labels_resource_and_note_links(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Personal']);
        $note = $area->notes()->create(['title' => 'Idea', 'content' => 'Details']);
        $resource = $user->resources()->create(['title' => 'Reference']);
        Passport::actingAs($user);

        $boardUuid = $this->postJson(route('board.store'), ['name' => 'Life'])->assertCreated()->json('data.uuid');
        $board = Board::where('uuid', $boardUuid)->firstOrFail();
        $labelUuid = $this->postJson(route('board.labels.store', $board), [
            'name' => 'Important',
            'color' => 'red',
        ])->assertCreated()->json('data.uuid');

        $taskUuid = $this->postJson(route('board.tasks.store', $board), [
            'title' => 'Read reference',
            'description' => null,
            'priority' => 'high',
            'stage' => 'backlog',
            'label_uuids' => [$labelUuid],
            'resource_uuids' => [$resource->uuid],
            'note_uuids' => [$note->uuid],
        ])->assertCreated()
            ->assertJsonCount(1, 'data.labels')
            ->assertJsonCount(1, 'data.resources')
            ->assertJsonCount(1, 'data.notes')
            ->json('data.uuid');

        $task = $board->tasks()->where('uuid', $taskUuid)->firstOrFail();
        $this->patchJson(route('board.tasks.move', [$board, $task]), [
            'stage' => 'done',
            'position' => 0,
        ])->assertOk()->assertJsonPath('data.stage', 'done');
    }

    public function test_users_cannot_access_another_users_standalone_board(): void
    {
        $owner = User::factory()->create();
        Passport::actingAs($owner);
        $uuid = $this->postJson(route('board.store'), [])->assertCreated()->json('data.uuid');

        Passport::actingAs(User::factory()->create());
        $this->getJson(route('board.show', Board::where('uuid', $uuid)->firstOrFail()))->assertNotFound();
    }
}
