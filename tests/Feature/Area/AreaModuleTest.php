<?php

namespace Tests\Feature\Area;

use App\Models\Project;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AreaModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_areas_can_be_filtered_archived_and_restored(): void
    {
        $user = User::factory()->create();
        $active = $user->areas()->create(['name' => 'Health']);
        $archived = $user->areas()->create(['name' => 'Work']);
        $archived->update(['archived_at' => now()]);
        Passport::actingAs($user);

        $this->getJson(route('area.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $active->uuid);

        $this->getJson(route('area.index', ['status' => 'archived']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $archived->uuid);

        $this->postJson(route('area.archive', $active))->assertOk();
        $this->postJson(route('area.archive', $active))->assertOk();
        $this->postJson(route('area.restore', $active))->assertOk();
        $this->postJson(route('area.restore', $active))->assertOk();
        $this->assertNull($active->fresh()->archived_at);
    }

    public function test_area_names_are_trimmed_and_unique_by_slug_for_each_user(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->postJson(route('area.store'), ['name' => '  Work Life  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Work Life')
            ->assertJsonPath('data.slug', 'work-life');

        $this->postJson(route('area.store'), ['name' => 'Work-Life'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_archived_areas_are_read_only_until_restored(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Health', 'archived_at' => now()]);
        Passport::actingAs($user);

        $this->putJson(route('area.update', $area), ['name' => 'Fitness'])->assertConflict();
        $this->postJson(route('area.goals.store', $area), ['title' => 'Run'])->assertConflict();
        $this->getJson(route('area.show', $area))->assertOk();

        $this->postJson(route('area.restore', $area))->assertOk();
        $this->postJson(route('area.goals.store', $area), ['title' => 'Run'])->assertCreated();
    }

    public function test_area_mutations_are_scoped_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $area = $owner->areas()->create(['name' => 'Private']);
        Passport::actingAs($otherUser);

        $this->putJson(route('area.update', $area), ['name' => 'Stolen'])->assertNotFound();
        $this->deleteJson(route('area.destroy', $area))->assertNotFound();
        $this->postJson(route('area.archive', $area))->assertNotFound();
        $this->assertDatabaseHas('areas', ['id' => $area->getKey(), 'name' => 'Private']);
    }

    public function test_goal_crud_tracks_completion_and_validates_dates(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Health']);
        Passport::actingAs($user);

        $response = $this->postJson(route('area.goals.store', $area), [
            'title' => 'Run a marathon',
            'status' => 'in_progress',
            'start_date' => '2026-09-01',
            'due_date' => '2026-08-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('due_date');

        $response = $this->postJson(route('area.goals.store', $area), [
            'title' => 'Run a marathon',
            'status' => 'in_progress',
            'start_date' => '2026-09-01',
            'due_date' => '2026-10-01',
        ])->assertCreated();
        $goalUuid = $response->json('data.uuid');

        $this->putJson(route('area.goals.update', [$area, $goalUuid]), ['start_date' => '2026-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_date');

        $this->putJson(route('area.goals.update', [$area, $goalUuid]), ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_at', fn ($value) => $value !== null);

        $this->getJson(route('area.goals.index', $area))
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->deleteJson(route('area.goals.destroy', [$area, $goalUuid]))->assertOk();
        $this->assertSoftDeleted('goals', ['uuid' => $goalUuid]);
    }

    public function test_habit_and_note_crud_persist_practical_fields(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Mindset']);
        Passport::actingAs($user);

        $habit = $this->postJson(route('area.habits.store', $area), [
            'name' => 'Meditate',
            'frequency' => 'weekly',
            'schedule' => ['days' => ['monday', 'friday']],
        ])->assertCreated()->json('data');

        $this->putJson(route('area.habits.update', [$area, $habit['uuid']]), ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $unpinned = $this->postJson(route('area.notes.store', $area), [
            'title' => 'Later',
            'content' => 'Unpinned note',
        ])->assertCreated()->json('data');
        $pinned = $this->postJson(route('area.notes.store', $area), [
            'title' => 'Important',
            'content' => 'Pinned note',
            'is_pinned' => true,
        ])->assertCreated()->json('data');

        $this->getJson(route('area.notes.index', $area))
            ->assertOk()
            ->assertJsonPath('data.data.0.uuid', $pinned['uuid']);

        $this->deleteJson(route('area.habits.destroy', [$area, $habit['uuid']]))->assertOk();
        $this->deleteJson(route('area.notes.destroy', [$area, $unpinned['uuid']]))->assertOk();
    }

    public function test_projects_can_be_reassigned_and_detached_from_an_area(): void
    {
        $user = User::factory()->create();
        $firstArea = $user->areas()->create(['name' => 'Work']);
        $secondArea = $user->areas()->create(['name' => 'Learning']);
        $project = new Project(['name' => 'Course', 'slug' => 'course']);
        $user->projects()->save($project);
        $project->area()->associate($firstArea)->save();
        Passport::actingAs($user);

        $this->postJson(route('area.projects.store', $secondArea), ['project_uuid' => $project->uuid])
            ->assertOk()
            ->assertJsonPath('data.area.uuid', $secondArea->uuid);
        $this->assertSame($secondArea->getKey(), $project->fresh()->area_id);

        $this->deleteJson(route('area.projects.destroy', [$secondArea, $project]))->assertOk();
        $this->assertNull($project->fresh()->area_id);
    }

    public function test_resources_can_be_linked_idempotently_and_detached(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Reading']);
        $resource = new Resource(['title' => 'Book']);
        $user->resources()->save($resource);
        Passport::actingAs($user);

        $payload = ['resource_uuid' => $resource->uuid];
        $this->postJson(route('area.resources.store', $area), $payload)->assertOk();
        $this->postJson(route('area.resources.store', $area), $payload)->assertOk();
        $this->assertDatabaseCount('area_resource', 1);

        $this->getJson(route('area.resources.index', $area))
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->deleteJson(route('area.resources.destroy', [$area, $resource]))->assertOk();
        $this->assertDatabaseHas('resources', ['id' => $resource->getKey()]);
        $this->assertDatabaseCount('area_resource', 0);
    }

    public function test_foreign_projects_resources_and_nested_records_are_rejected(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Mine']);
        $otherArea = $owner->areas()->create(['name' => 'Theirs']);
        $project = new Project(['name' => 'Secret']);
        $owner->projects()->save($project);
        $resource = new Resource(['title' => 'Secret']);
        $owner->resources()->save($resource);
        $goal = $otherArea->goals()->create(['title' => 'Secret goal']);
        Passport::actingAs($user);

        $this->postJson(route('area.projects.store', $area), ['project_uuid' => $project->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('project_uuid');
        $this->postJson(route('area.resources.store', $area), ['resource_uuid' => $resource->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('resource_uuid');
        $this->getJson(route('area.goals.show', [$area, $goal]))->assertNotFound();
    }
}
