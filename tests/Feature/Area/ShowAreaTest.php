<?php

namespace Tests\Feature\Area;

use App\Models\Project;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ShowAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_area_is_returned_with_its_projects_and_resources(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create([
            'name' => 'Health',
            'description' => 'Health-related work and references.',
        ]);

        $project = new Project([
            'name' => 'Daily Exercise',
            'slug' => 'daily-exercise',
        ]);
        $project->user_id = $user->getKey();
        $area->projects()->save($project);

        $resource = new Resource([
            'title' => 'Exercise Guide',
            'type' => 'article',
        ]);
        $resource->user_id = $user->getKey();
        $resource->save();
        $area->resources()->attach($resource);

        Passport::actingAs($user);

        $response = $this->getJson(route('area.show', $area));

        $response
            ->assertOk()
            ->assertJsonPath('data.uuid', $area->uuid)
            ->assertJsonPath('data.projects.0.uuid', $project->uuid)
            ->assertJsonPath('data.resources.0.uuid', $resource->uuid)
            ->assertJsonCount(1, 'data.projects')
            ->assertJsonCount(1, 'data.resources');
    }

    public function test_a_user_cannot_view_another_users_area(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $area = $owner->areas()->create(['name' => 'Private Area']);

        Passport::actingAs($otherUser);

        $this->getJson(route('area.show', $area))->assertNotFound();
    }
}
