<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

function projectNamed(User $user, string $name): Project
{
    Passport::actingAs($user);
    $uuid = test()->postJson(route('project.store'), ['name' => $name])->assertCreated()->json('data.uuid');

    return Project::where('uuid', $uuid)->sole();
}

it('rejects a duplicate project name, including names that only differ in punctuation or case', function (string $name): void {
    $user = User::factory()->create();
    projectNamed($user, 'Launch');

    $this->postJson(route('project.store'), ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'A project with this name already exists.');
    expect($user->projects()->count())->toBe(1);
})->with(['Launch', 'launch', 'Launch!']);

it('rejects renaming a project to a name another project already uses but allows keeping its own name', function (): void {
    $user = User::factory()->create();
    projectNamed($user, 'Launch');
    $other = projectNamed($user, 'Research');

    $this->putJson(route('project.update', $other), ['name' => 'Launch'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'A project with this name already exists.');
    $this->putJson(route('project.update', $other), ['name' => 'Research', 'description' => 'Kept'])->assertOk();
});

it('points to Trash when the name belongs to a trashed project', function (): void {
    $user = User::factory()->create();
    $project = projectNamed($user, 'Launch');
    $this->deleteJson(route('project.destroy', $project))->assertOk();

    $this->postJson(route('project.store'), ['name' => 'Launch'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'A project with this name is in Trash. Restore it or choose another name.');
});

it('rejects a name with no letters or numbers instead of failing on an empty slug', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson(route('project.store'), ['name' => '!!!'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'The project name must contain at least one letter or number.');
});

it('checks a partial date update against the stored start date', function (): void {
    $user = User::factory()->create();
    $project = projectNamed($user, 'Launch');
    $this->putJson(route('project.update', $project), ['start_date' => '2026-10-01', 'due_date' => '2026-10-31'])->assertOk();

    $this->putJson(route('project.update', $project), ['due_date' => '2026-09-01'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.due_date.0', 'The due date must be after or equal to the start date.');
    expect($project->fresh()->due_date->toDateString())->toBe('2026-10-31');
});

it('keeps archived projects read-only for edits, area changes, and area links', function (): void {
    $user = User::factory()->create();
    $project = projectNamed($user, 'Launch');
    $area = $user->areas()->create(['name' => 'Work']);
    $this->postJson(route('project.archive', $project))->assertOk();

    $this->putJson(route('project.update', $project), ['name' => 'Renamed'])->assertConflict();
    $this->patchJson(route('project.area.update', $project), ['area_uuid' => $area->uuid])->assertConflict();
    $this->postJson(route('area.projects.store', $area), ['project_uuid' => $project->uuid])->assertConflict();

    expect($project->fresh())->name->toBe('Launch')->area_id->toBeNull();
});
