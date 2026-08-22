<?php

namespace Tests\Feature\Project;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class StoreProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_can_be_created_in_the_selected_area(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Mindset']);
        Passport::actingAs($user);

        $response = $this->postJson(route('project.store'), [
            'name' => 'Daily Reflection',
            'area_uuid' => $area->uuid,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Daily Reflection')
            ->assertJsonPath('data.area.uuid', $area->uuid);

        $this->assertDatabaseHas('projects', [
            'user_id' => $user->getKey(),
            'area_id' => $area->getKey(),
            'name' => 'Daily Reflection',
        ]);
        $this->assertDatabaseCount('areas', 1);
    }

    public function test_an_existing_area_is_reused_when_creating_by_name(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Mindset']);
        Passport::actingAs($user);

        $response = $this->postJson(route('project.store'), [
            'name' => 'Read Stoicism',
            'area_name' => 'Mindset',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.area.uuid', $area->uuid);

        $this->assertDatabaseCount('areas', 1);
    }

    public function test_a_new_area_is_created_when_the_given_name_does_not_exist(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $response = $this->postJson(route('project.store'), [
            'name' => 'Build Emergency Fund',
            'area_name' => 'Finances',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.area.name', 'Finances');

        $areaId = $response->json('data.area.id');

        $this->assertDatabaseHas('areas', [
            'id' => $areaId,
            'user_id' => $user->getKey(),
            'name' => 'Finances',
        ]);
        $this->assertDatabaseHas('projects', [
            'user_id' => $user->getKey(),
            'area_id' => $areaId,
            'name' => 'Build Emergency Fund',
        ]);
    }

    public function test_an_area_is_required_and_must_belong_to_the_user(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $foreignArea = $owner->areas()->create(['name' => 'Private']);
        Passport::actingAs($user);

        $this->postJson(route('project.store'), [
            'name' => 'Unassigned Project',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'area_uuid',
            'area_name',
        ]);

        $this->postJson(route('project.store'), [
            'name' => 'Foreign Project',
            'area_uuid' => $foreignArea->uuid,
        ])->assertUnprocessable()->assertJsonValidationErrors('area_uuid');

        $this->assertDatabaseCount('projects', 0);
    }
}
