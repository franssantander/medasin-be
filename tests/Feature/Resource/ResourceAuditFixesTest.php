<?php

use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('keeps fields and links that a partial update leaves out', function (): void {
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Work']);
    Passport::actingAs($user);
    $projectUuid = $this->postJson(route('project.store'), ['name' => 'Launch'])->json('data.uuid');
    $uuid = $this->postJson(route('resource.store'), [
        'title' => 'Doc',
        'icon' => 'BookOpen',
        'tag_names' => ['reference'],
        'project_uuids' => [$projectUuid],
        'area_uuids' => [$area->uuid],
    ])->assertCreated()->json('data.uuid');

    $this->patchJson(route('resource.update', $uuid), ['title' => 'Doc renamed'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Doc renamed')
        ->assertJsonPath('data.icon', 'BookOpen')
        ->assertJsonCount(1, 'data.tags')
        ->assertJsonCount(1, 'data.projects')
        ->assertJsonCount(1, 'data.areas');

    $this->patchJson(route('resource.update', $uuid), ['tag_names' => [], 'tag_uuids' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data.tags')
        ->assertJsonCount(1, 'data.projects');
});

it('keeps links to archived projects when the other links are edited', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);
    $archivedUuid = $this->postJson(route('project.store'), ['name' => 'Old launch'])->json('data.uuid');
    $activeUuid = $this->postJson(route('project.store'), ['name' => 'New launch'])->json('data.uuid');
    $uuid = $this->postJson(route('resource.store'), ['title' => 'Doc', 'project_uuids' => [$archivedUuid]])->json('data.uuid');
    $this->postJson(route('project.archive', $archivedUuid))->assertOk();

    $this->patchJson(route('resource.update', $uuid), ['project_uuids' => [$activeUuid]])
        ->assertOk()
        ->assertJsonCount(2, 'data.projects');
});

it('restores area, project, and board task links when an archived resource is unarchived', function (): void {
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Work']);
    Passport::actingAs($user);
    $projectUuid = $this->postJson(route('project.store'), ['name' => 'Launch'])->json('data.uuid');
    $resource = Resource::where('uuid', $this->postJson(route('resource.store'), [
        'title' => 'Doc',
        'project_uuids' => [$projectUuid],
        'area_uuids' => [$area->uuid],
    ])->json('data.uuid'))->sole();

    $this->postJson(route('resource.archive', $resource->uuid))->assertOk()->assertJsonCount(0, 'data.projects');
    $this->postJson(route('resource.archive', $resource->uuid))->assertOk();
    $this->postJson(route('resource.restore', $resource->uuid))
        ->assertOk()
        ->assertJsonCount(1, 'data.projects')
        ->assertJsonCount(1, 'data.areas')
        ->assertJsonMissingPath('data.archived_links');
});

it('does not restore links to contexts deleted while the resource was archived', function (): void {
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Work']);
    Passport::actingAs($user);
    $uuid = $this->postJson(route('resource.store'), ['title' => 'Doc', 'area_uuids' => [$area->uuid]])->json('data.uuid');

    $this->postJson(route('resource.archive', $uuid))->assertOk();
    $this->deleteJson(route('area.destroy', $area))->assertOk();

    $this->postJson(route('resource.restore', $uuid))->assertOk()->assertJsonCount(0, 'data.areas');
});
