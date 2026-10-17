<?php

use App\Models\BoardTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('keeps task positions unique after a task is deleted', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);
    $project = Project::where('uuid', $this->postJson(route('project.store'), ['name' => 'Launch'])->json('data.uuid'))->sole();
    $board = $project->boards()->firstOrFail();
    $create = fn (string $title): string => $this->postJson(route('project.boards.tasks.store', [$project, $board]), [
        'title' => $title,
        'stage' => 'todos',
    ])->assertCreated()->json('data.uuid');

    $create('A');
    $b = $create('B');
    $create('C');
    $this->deleteJson(route('project.boards.tasks.destroy', [$project, $board, BoardTask::where('uuid', $b)->sole()]))->assertOk();
    $create('D');

    expect($board->tasks()->orderBy('position')->pluck('position', 'title')->all())
        ->toBe(['A' => 0, 'C' => 1, 'D' => 2]);
});

it('points to Trash when a label name belongs to a trashed label', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);
    $project = Project::where('uuid', $this->postJson(route('project.store'), ['name' => 'Launch'])->json('data.uuid'))->sole();
    $board = $project->boards()->firstOrFail();
    $label = $this->postJson(route('project.boards.labels.store', [$project, $board]), ['name' => 'Urgent', 'color' => 'red'])
        ->assertCreated()->json('data.uuid');

    $this->postJson(route('project.boards.labels.store', [$project, $board]), ['name' => 'Urgent', 'color' => 'blue'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'A label with this name already exists.');

    $this->deleteJson(route('project.boards.labels.destroy', [$project, $board, $board->labels()->where('uuid', $label)->sole()]))->assertOk();
    $this->postJson(route('project.boards.labels.store', [$project, $board]), ['name' => 'Urgent', 'color' => 'blue'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'A label with this name is in Trash. Restore it or choose another name.');
});
