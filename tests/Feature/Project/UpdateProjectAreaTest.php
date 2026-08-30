<?php

namespace Tests\Feature\Project;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class UpdateProjectAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_active_area_can_be_assigned(): void
    {
        $user = User::factory()->create();
        $originalArea = $user->areas()->create(['name' => 'Work']);
        $newArea = $user->areas()->create(['name' => 'Health']);
        $newArea->goals()->create(['title' => 'Run a marathon']);
        $project = $user->projects()->create(['area_id' => $originalArea->id, 'name' => 'Fitness']);
        Passport::actingAs($user);

        $this->patchJson(route('project.area.update', $project), [
            'area_uuid' => $newArea->uuid,
        ])->assertOk()
            ->assertJsonPath('message', 'Successfully updated project area.')
            ->assertJsonPath('data.uuid', $project->uuid)
            ->assertJsonPath('data.area.uuid', $newArea->uuid)
            ->assertJsonPath('data.goals.count', 1);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'area_id' => $newArea->id,
        ]);
    }

    public function test_a_new_area_can_be_created_and_assigned_by_name(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Budget']);
        Passport::actingAs($user);

        $this->patchJson(route('project.area.update', $project), [
            'area_name' => '  Finances  ',
        ])->assertOk()
            ->assertJsonPath('data.area.name', 'Finances');

        $area = $user->areas()->where('slug', 'finances')->firstOrFail();
        $this->assertTrue($project->fresh()->area->is($area));
    }

    public function test_an_existing_active_area_is_reused_by_name(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Mindset']);
        $project = $user->projects()->create(['name' => 'Read']);
        Passport::actingAs($user);

        $this->patchJson(route('project.area.update', $project), [
            'area_name' => 'Mindset',
        ])->assertOk()->assertJsonPath('data.area.uuid', $area->uuid);

        $this->assertDatabaseCount('areas', 1);
    }

    public function test_an_assigned_project_can_be_moved_to_the_inbox(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Work']);
        $project = $user->projects()->create([
            'area_id' => $area->id,
            'name' => 'Unassigned work',
        ]);
        Passport::actingAs($user);

        $this->patchJson(route('project.area.update', $project), [])
            ->assertOk()
            ->assertJsonPath('data.area', null)
            ->assertJsonPath('data.goals.count', 0);

        $this->assertNull($project->fresh()->area_id);
    }

    public function test_area_input_must_identify_exactly_one_available_owned_area(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Private project']);
        $activeArea = $user->areas()->create(['name' => 'Active']);
        $archivedArea = $user->areas()->create(['name' => 'Archived', 'archived_at' => now()]);
        $deletedArea = $user->areas()->create(['name' => 'Deleted']);
        $deletedArea->delete();
        $foreignArea = $otherUser->areas()->create(['name' => 'Foreign']);
        Passport::actingAs($user);

        $this->patchJson(route('project.area.update', $project), [
            'area_uuid' => $activeArea->uuid,
            'area_name' => 'Another',
        ])->assertUnprocessable()->assertJsonValidationErrors(['area_uuid', 'area_name']);

        foreach ([$archivedArea, $deletedArea, $foreignArea] as $area) {
            $this->patchJson(route('project.area.update', $project), [
                'area_uuid' => $area->uuid,
            ])->assertUnprocessable()->assertJsonValidationErrors('area_uuid');
        }
    }

    public function test_an_archived_or_deleted_area_name_cannot_be_recreated(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Project']);
        $user->areas()->create(['name' => 'Archived', 'archived_at' => now()]);
        $deletedArea = $user->areas()->create(['name' => 'Deleted']);
        $deletedArea->delete();
        Passport::actingAs($user);

        foreach (['Archived', 'Deleted'] as $name) {
            $this->patchJson(route('project.area.update', $project), [
                'area_name' => $name,
            ])->assertUnprocessable()->assertJsonValidationErrors('area_name');
        }
    }

    public function test_a_project_owned_by_another_user_cannot_be_changed(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $project = $owner->projects()->create(['name' => 'Private']);
        $area = $user->areas()->create(['name' => 'Work']);
        Passport::actingAs($user);

        $this->patchJson(route('project.area.update', $project), [
            'area_uuid' => $area->uuid,
        ])->assertNotFound();
    }
}
