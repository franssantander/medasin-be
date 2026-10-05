<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Passport\Passport;

pest()->use(DatabaseMigrations::class)->group('pest-features');

it('refreshes cached parent resource lists after resource links are cleared', function (string $store): void {
    config(['cache.api_reads.store' => $store, 'cache.api_reads.enabled' => true]);
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Research']);
    $project = $user->projects()->create(['name' => 'Research project']);
    Passport::actingAs($user);
    $uuid = $this->postJson(route('resource.store'), [
        'title' => 'Research reference', 'area_uuids' => [$area->uuid], 'project_uuids' => [$project->uuid],
    ])->assertCreated()->json('data.uuid');
    $resource = $user->resources()->where('uuid', $uuid)->sole();
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.data.0.uuid', $uuid);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonPath('data.resources.0.uuid', $uuid);
    $this->getJson(route('resource.show', $resource))->assertOk()->assertJsonCount(1, 'data.areas');

    $this->patchJson(route('resource.update', $resource), [
        'title' => 'Unlinked reference', 'area_uuids' => [], 'project_uuids' => [],
    ])->assertOk();
    $this->getJson(route('area.resources.index', $area))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('project.show', $project))->assertOk()->assertJsonCount(0, 'data.resources');
    $this->getJson(route('resource.show', $resource))->assertOk()
        ->assertJsonPath('data.title', 'Unlinked reference')->assertJsonCount(0, 'data.areas')
        ->assertJsonCount(0, 'data.projects');

    $this->assertDatabaseHas('resources', ['id' => $resource->id, 'title' => 'Unlinked reference']);
    $this->assertDatabaseMissing('area_resource', ['resource_id' => $resource->id]);
    $this->assertDatabaseMissing('project_resource', ['resource_id' => $resource->id]);
})->with(['memory' => ['array'], 'database fallback' => ['database']]);
