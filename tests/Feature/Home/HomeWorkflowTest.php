<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('updates dashboard progress and archive counts after project board actions', function (): void {
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Work']);
    Passport::actingAs($user);
    $uuid = $this->postJson(route('project.store'), ['name' => 'Launch', 'area_uuid' => $area->uuid])
        ->assertCreated()->json('data.uuid');
    $project = $user->projects()->where('uuid', $uuid)->sole();
    $board = $project->boards()->firstOrFail();
    $taskUuid = $this->postJson(route('project.boards.tasks.store', [$project, $board]), ['title' => 'Ship the release'])
        ->assertCreated()->json('data.uuid');
    $task = $board->tasks()->where('uuid', $taskUuid)->sole();

    $this->patchJson(route('project.boards.tasks.move', [$project, $board, $task]), ['stage' => 'done', 'position' => 0])->assertOk();
    $this->getJson(route('home.show'))->assertOk()
        ->assertJsonPath('data.projects.0.completed_tasks', 1)
        ->assertJsonPath('data.projects.0.progress_percentage', 100)
        ->assertJsonPath('data.areas.0.projects_count', 1);
    $this->postJson(route('project.archive', $project))->assertOk();
    $this->getJson(route('home.show'))->assertOk()
        ->assertJsonPath('data.stats.active_projects', 0)
        ->assertJsonPath('data.archives.projects', 1)
        ->assertJsonPath('data.areas.0.projects_count', 0);
    $this->postJson(route('project.restore', $project))->assertOk();
    $this->getJson(route('home.show'))->assertOk()
        ->assertJsonPath('data.stats.active_projects', 1)
        ->assertJsonPath('data.projects.0.uuid', $uuid)
        ->assertJsonPath('data.projects.0.completed_tasks', 1);

    $this->assertDatabaseHas('projects', ['id' => $project->id, 'archived_at' => null]);
    $this->assertModelExists($task);
});
