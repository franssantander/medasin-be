<?php

namespace Tests\Feature\Project;

use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProjectKanbanBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_projects_receive_a_default_board_with_four_stages(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $projectUuid = $this->postJson(route('project.store'), ['name' => 'Launch product'])
            ->assertCreated()
            ->json('data.uuid');
        $project = Project::where('uuid', $projectUuid)->firstOrFail();

        $this->getJson(route('project.boards.index', $project))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Board 1')
            ->assertJsonPath('data.0.task_count', 0)
            ->assertJsonPath('data.0.stage_counts.backlog', 0);

        $board = $project->boards()->firstOrFail();
        $this->assertSame(
            ['backlog', 'todos', 'in_progress', 'done'],
            $board->stages()->get()->map(fn ($stage) => $stage->key->value)->all(),
        );
    }

    public function test_boards_can_be_created_renamed_and_deleted_but_the_last_board_is_protected(): void
    {
        [$user, $project, $board] = $this->projectWithBoard();
        Passport::actingAs($user);

        $secondBoardUuid = $this->postJson(route('project.boards.store', $project), [])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Board 2')
            ->json('data.uuid');
        $secondBoard = Board::where('uuid', $secondBoardUuid)->firstOrFail();

        $this->putJson(route('project.boards.update', [$project, $secondBoard]), ['name' => 'Delivery'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Delivery');

        $this->deleteJson(route('project.boards.destroy', [$project, $board]))->assertOk();
        $this->deleteJson(route('project.boards.destroy', [$project, $secondBoard]))
            ->assertConflict()
            ->assertJsonPath('message', 'A project must keep at least one board.');
    }

    public function test_tasks_support_labels_links_ordering_and_project_progress(): void
    {
        [$user, $project, $board] = $this->projectWithBoard(['due_date' => now()->subDay()->toDateString()]);
        $area = $user->areas()->create(['name' => 'Work']);
        $note = $area->notes()->create(['title' => 'Research', 'content' => 'Notes']);
        $resource = $user->resources()->create(['title' => 'Specification']);
        Passport::actingAs($user);

        $labelUuid = $this->postJson(route('project.boards.labels.store', [$project, $board]), [
            'name' => 'Backend',
            'color' => 'blue',
        ])->assertCreated()
            ->assertJsonPath('data.hex', '#3B82F6')
            ->json('data.uuid');

        $firstTaskUuid = $this->postJson(route('project.boards.tasks.store', [$project, $board]), [
            'title' => 'Build API',
            'priority' => 'high',
            'label_uuids' => [$labelUuid],
            'resource_uuids' => [$resource->uuid],
            'note_uuids' => [$note->uuid],
        ])->assertCreated()
            ->assertJsonPath('data.stage', 'backlog')
            ->assertJsonPath('data.position', 0)
            ->assertJsonPath('data.labels.0.name', 'Backend')
            ->assertJsonPath('data.resources.0.uuid', $resource->uuid)
            ->assertJsonPath('data.notes.0.uuid', $note->uuid)
            ->json('data.uuid');

        $secondTaskUuid = $this->postJson(route('project.boards.tasks.store', [$project, $board]), [
            'title' => 'Write tests',
        ])->assertCreated()->json('data.uuid');

        $firstTask = $board->tasks()->where('uuid', $firstTaskUuid)->firstOrFail();
        $secondTask = $board->tasks()->where('uuid', $secondTaskUuid)->firstOrFail();

        $this->patchJson(route('project.boards.tasks.move', [$project, $board, $secondTask]), [
            'stage' => 'backlog',
            'position' => 0,
        ])->assertOk()->assertJsonPath('data.position', 0);

        $this->getJson(route('project.boards.tasks.index', [$project, $board]))
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $secondTask->uuid)
            ->assertJsonPath('data.1.uuid', $firstTask->uuid);

        $this->patchJson(route('project.boards.tasks.move', [$project, $board, $firstTask]), [
            'stage' => 'done',
            'position' => 0,
        ])->assertOk();

        $this->getJson(route('project.index'))
            ->assertOk()
            ->assertJsonPath('data.0.status', 'in_progress')
            ->assertJsonPath('data.0.progress_percentage', 50)
            ->assertJsonPath('data.0.is_overdue', true);

        $this->patchJson(route('project.boards.tasks.move', [$project, $board, $secondTask]), [
            'stage' => 'done',
            'position' => 1,
        ])->assertOk();

        $this->getJson(route('project.show', $project))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress_percentage', 100)
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.boards.0.stage_counts.done', 2);
    }

    public function test_foreign_links_are_rejected_and_archived_projects_are_read_only(): void
    {
        [$user, $project, $board] = $this->projectWithBoard();
        $otherUser = User::factory()->create();
        $foreignResource = $otherUser->resources()->create(['title' => 'Private']);
        Passport::actingAs($user);

        $this->postJson(route('project.boards.tasks.store', [$project, $board]), [
            'title' => 'Invalid links',
            'resource_uuids' => [$foreignResource->uuid],
        ])->assertUnprocessable()->assertJsonValidationErrors('resource_uuids');

        $this->postJson(route('project.archive', $project))->assertOk();
        $this->postJson(route('project.boards.tasks.store', [$project, $board]), [
            'title' => 'Cannot mutate',
        ])->assertConflict();

        $this->getJson(route('project.boards.show', [$project, $board]))->assertOk();
    }

    /**
     * @return array{User, Project, Board}
     */
    private function projectWithBoard(array $attributes = []): array
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        $projectUuid = $this->postJson(route('project.store'), [
            'name' => 'Project '.fake()->unique()->word(),
            ...$attributes,
        ])->assertCreated()->json('data.uuid');
        $project = Project::where('uuid', $projectUuid)->firstOrFail();

        return [$user, $project, $project->boards()->firstOrFail()];
    }
}
