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
        $area->resources()->attach($resource);
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
            ->assertJsonPath('data.resources.0.areas.0.uuid', $area->uuid)
            ->assertJsonPath('data.resources.0.areas.0.name', 'Work')
            ->assertJsonPath('data.notes.0.uuid', $note->uuid)
            ->assertJsonPath('data.notes.0.area.uuid', $area->uuid)
            ->assertJsonPath('data.notes.0.area.name', 'Work')
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

    public function test_project_detail_includes_direct_and_task_resources_once(): void
    {
        [$user, $project, $board] = $this->projectWithBoard();
        $direct = $user->resources()->create(['title' => 'Direct resource']);
        $taskOnly = $user->resources()->create(['title' => 'Task resource']);
        $shared = $user->resources()->create(['title' => 'Shared resource']);
        $archived = $user->resources()->create(['title' => 'Archived resource']);
        $project->resources()->attach([$direct->id, $shared->id, $archived->id]);

        $taskUuid = $this->postJson(route('project.boards.tasks.store', [$project, $board]), [
            'title' => 'Collect references',
            'resource_uuids' => [$taskOnly->uuid, $shared->uuid, $archived->uuid],
        ])->assertCreated()->json('data.uuid');
        $archived->forceFill(['archived_at' => now()])->save();

        $this->getJson(route('project.show', $project))
            ->assertOk()
            ->assertJsonCount(3, 'data.resources')
            ->assertJsonFragment(['uuid' => $direct->uuid, 'title' => 'Direct resource'])
            ->assertJsonFragment(['uuid' => $taskOnly->uuid, 'title' => 'Task resource'])
            ->assertJsonFragment(['uuid' => $shared->uuid, 'title' => 'Shared resource'])
            ->assertJsonMissing(['uuid' => $archived->uuid]);

        $task = $board->tasks()->where('uuid', $taskUuid)->firstOrFail();
        $this->putJson(route('project.boards.tasks.update', [$project, $board, $task]), [
            'title' => $task->title,
            'resource_uuids' => [$shared->uuid],
        ])->assertOk();

        $this->getJson(route('project.show', $project))
            ->assertOk()
            ->assertJsonCount(2, 'data.resources')
            ->assertJsonMissing(['uuid' => $taskOnly->uuid]);
    }

    public function test_resources_can_be_linked_directly_to_an_active_project(): void
    {
        [$user, $project] = $this->projectWithBoard();
        $first = $user->resources()->create(['title' => 'First resource']);
        $second = $user->resources()->create(['title' => 'Second resource']);
        $archived = $user->resources()->create([
            'title' => 'Archived resource',
            'archived_at' => now(),
        ]);
        $foreign = User::factory()->create()->resources()->create(['title' => 'Private resource']);

        $this->postJson(route('project.resources.store', $project), [
            'resource_uuids' => [$first->uuid, $second->uuid],
        ])->assertOk()
            ->assertJsonPath('message', 'Successfully linked resources to project.');

        $this->postJson(route('project.resources.store', $project), [
            'resource_uuids' => [$first->uuid],
        ])->assertOk();
        $this->assertSame(2, $project->resources()->count());

        foreach ([$archived, $foreign] as $invalidResource) {
            $this->postJson(route('project.resources.store', $project), [
                'resource_uuids' => [$invalidResource->uuid],
            ])->assertUnprocessable()->assertJsonValidationErrors('resource_uuids.0');
        }

        $this->postJson(route('project.archive', $project))->assertOk();
        $this->postJson(route('project.resources.store', $project), [
            'resource_uuids' => [$first->uuid],
        ])->assertConflict();
    }

    public function test_resource_can_be_removed_from_an_active_project_and_its_tasks(): void
    {
        [$user, $project, $board] = $this->projectWithBoard();
        $resource = $user->resources()->create(['title' => 'Linked resource']);
        $project->resources()->attach($resource);
        $taskUuid = $this->postJson(route('project.boards.tasks.store', [$project, $board]), [
            'title' => 'Task with resource',
            'resource_uuids' => [$resource->uuid],
        ])->assertCreated()->json('data.uuid');

        $this->deleteJson(route('project.resources.destroy', [$project, $resource]))
            ->assertOk()
            ->assertJsonPath('message', 'Successfully removed resource from project.');

        $this->assertFalse($project->resources()->whereKey($resource->getKey())->exists());
        $task = $board->tasks()->where('uuid', $taskUuid)->firstOrFail();
        $this->assertFalse($task->resources()->whereKey($resource->getKey())->exists());
        $this->assertDatabaseHas('resources', ['id' => $resource->getKey()]);
    }

    public function test_resource_removal_requires_owned_active_project_and_linked_resource(): void
    {
        [$user, $project] = $this->projectWithBoard();
        $linked = $user->resources()->create(['title' => 'Linked resource']);
        $unlinked = $user->resources()->create(['title' => 'Unlinked resource']);
        $foreign = User::factory()->create()->resources()->create(['title' => 'Private resource']);
        $project->resources()->attach($linked);

        $this->deleteJson(route('project.resources.destroy', [$project, $unlinked]))->assertNotFound();
        $this->deleteJson(route('project.resources.destroy', [$project, $foreign]))->assertNotFound();

        $this->postJson(route('project.archive', $project))->assertOk();
        $this->deleteJson(route('project.resources.destroy', [$project, $linked]))->assertConflict();
        $this->assertTrue($project->resources()->whereKey($linked->getKey())->exists());
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
