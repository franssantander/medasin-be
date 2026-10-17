<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('points to Trash when an area name belongs to a trashed area', function (): void {
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Health']);
    Passport::actingAs($user);
    $this->deleteJson(route('area.destroy', $area))->assertOk();

    $this->postJson(route('area.store'), ['name' => 'Health'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'An area with this name is in Trash. Restore it or choose another name.');
});

it('moves Inbox projects back when an archived area is restored, leaving moved ones alone', function (): void {
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Career']);
    $elsewhere = $user->areas()->create(['name' => 'Side work']);
    $returns = $user->projects()->make(['name' => 'Find a mentor']);
    $returns->area()->associate($area)->save();
    $moved = $user->projects()->make(['name' => 'Update portfolio']);
    $moved->area()->associate($area)->save();
    Passport::actingAs($user);

    $this->postJson(route('area.archive', $area))->assertOk();
    expect($returns->fresh()->area_id)->toBeNull();
    $this->patchJson(route('project.area.update', $moved), ['area_uuid' => $elsewhere->uuid])->assertOk();

    $this->postJson(route('area.restore', $area))
        ->assertOk()
        ->assertJsonPath('message', 'Successfully restored area. 1 project moved back from Inbox.')
        ->assertJsonMissingPath('data.archived_project_ids');

    expect($returns->fresh()->area_id)->toBe($area->id)
        ->and($moved->fresh()->area_id)->toBe($elsewhere->id)
        ->and($area->fresh()->archived_project_ids)->toBeNull();
});

it('keeps the remembered projects when an already archived area is archived again', function (): void {
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Career']);
    $project = $user->projects()->make(['name' => 'Find a mentor']);
    $project->area()->associate($area)->save();
    Passport::actingAs($user);

    $this->postJson(route('area.archive', $area))->assertOk();
    $this->postJson(route('area.archive', $area))->assertOk();
    $this->postJson(route('area.restore', $area))->assertOk();

    expect($project->fresh()->area_id)->toBe($area->id);
});
