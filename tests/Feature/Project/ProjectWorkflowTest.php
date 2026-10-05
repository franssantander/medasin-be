<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('keeps project boards and task identities when a project changes areas', function (): void {
    $user = User::factory()->create();
    $first = $user->areas()->create(['name' => 'Work']);
    $second = $user->areas()->create(['name' => 'Study']);
    Passport::actingAs($user);
    $projectUuid = $this->postJson(route('project.store'), ['name' => 'Research', 'area_uuid' => $first->uuid])
        ->assertCreated()->json('data.uuid');
    $project = $user->projects()->where('uuid', $projectUuid)->sole();
    $board = $project->boards()->firstOrFail();
    $taskUuid = $this->postJson(route('project.boards.tasks.store', [$project, $board]), ['title' => 'Read sources'])
        ->assertCreated()->json('data.uuid');

    $this->patchJson(route('project.area.update', $project), ['area_uuid' => $second->uuid])->assertOk();
    $this->getJson(route('area.projects.index', $first))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('area.projects.index', $second))->assertOk()->assertJsonPath('data.data.0.uuid', $projectUuid);
    $this->getJson(route('project.boards.show', [$project, $board]))
        ->assertOk()->assertJsonPath('data.stages.0.tasks.0.uuid', $taskUuid);

    $this->assertDatabaseHas('projects', ['id' => $project->id, 'area_id' => $second->id]);
    $this->assertDatabaseHas('board_tasks', ['uuid' => $taskUuid, 'board_id' => $board->id]);
});

it('returns 422 and preserves its area when reassigned to another accounts area', function (): void {
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Owned']);
    $project = $user->projects()->create(['name' => 'Owned project']);
    $project->area()->associate($area);
    $project->saveOrFail();
    $foreign = User::factory()->create()->areas()->create(['name' => 'Private']);
    Passport::actingAs($user);

    $this->patchJson(route('project.area.update', $project), ['area_uuid' => $foreign->uuid])
        ->assertUnprocessable()->assertJsonValidationErrors('area_uuid');

    $this->assertDatabaseHas('projects', ['id' => $project->id, 'area_id' => $area->id]);
});
