<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProjectListTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_list_returns_card_ready_data_for_the_authenticated_user(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $area = $user->areas()->create([
            'name' => 'Career',
            'icon' => 'Briefcase',
        ]);
        $area->goals()->create(['title' => 'Active goal']);
        $deletedGoal = $area->goals()->create(['title' => 'Deleted goal']);
        $deletedGoal->delete();

        Carbon::setTestNow('2026-08-29 12:00:00');
        $olderProject = $this->createProject($user, ['name' => 'Older project']);
        Carbon::setTestNow('2026-08-30 12:00:00');
        $newerProject = $this->createProject($user, [
            'name' => 'Career move',
            'description' => 'Prepare for the next role.',
            'icon' => 'Rocket',
            'background' => '#DBEAFE',
            'status' => 'active',
            'start_date' => '2026-08-01',
            'due_date' => '2026-08-28',
        ], $area);
        $this->createProject($otherUser, ['name' => 'Private project']);

        Passport::actingAs($user);

        $response = $this->getJson(route('project.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.uuid', $newerProject->uuid)
            ->assertJsonPath('data.0.name', 'Career move')
            ->assertJsonPath('data.0.status', 'not_started')
            ->assertJsonPath('data.0.progress_percentage', 0)
            ->assertJsonPath('data.0.background', '#DBEAFE')
            ->assertJsonPath('data.0.start_date', '2026-08-01')
            ->assertJsonPath('data.0.due_date', '2026-08-28')
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.0.days_overdue', 2)
            ->assertJsonPath('data.0.area.uuid', $area->uuid)
            ->assertJsonPath('data.0.area.name', 'Career')
            ->assertJsonPath('data.0.archived_at', null)
            ->assertJsonPath('data.0.goals.count', 1)
            ->assertJsonPath('data.0.goals.url', route('area.goals.index', $area))
            ->assertJsonPath('data.1.uuid', $olderProject->uuid)
            ->assertJsonPath('data.1.area', null)
            ->assertJsonPath('data.1.goals.count', 0)
            ->assertJsonPath('data.1.goals.url', null)
            ->assertJsonPath('data.1.is_overdue', false)
            ->assertJsonPath('data.1.days_overdue', null);

    }

    public function test_a_deadline_today_is_not_overdue(): void
    {
        Carbon::setTestNow('2026-08-30 23:59:00');

        $user = User::factory()->create();
        $this->createProject($user, [
            'name' => 'Due today',
            'due_date' => '2026-08-30',
        ]);
        Passport::actingAs($user);

        $this->getJson(route('project.index'))
            ->assertOk()
            ->assertJsonPath('data.0.is_overdue', false)
            ->assertJsonPath('data.0.days_overdue', null);
    }

    public function test_project_list_requires_authentication(): void
    {
        $this->getJson(route('project.index'))->assertUnauthorized();
    }

    public function test_projects_can_be_archived_filtered_and_restored(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user, ['name' => 'Archive me']);
        Passport::actingAs($user);

        $this->postJson(route('project.archive', $project))
            ->assertOk()
            ->assertJsonPath('data.uuid', $project->uuid);

        $this->getJson(route('project.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson(route('project.index', ['status' => 'archived']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $project->uuid)
            ->assertJsonPath(
                'data.0.archived_at',
                fn ($value) => is_string($value),
            );

        $this->postJson(route('project.restore', $project))
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);

        $this->getJson(route('project.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProject(User $user, array $attributes, mixed $area = null): Project
    {
        $project = $user->projects()->make($attributes);

        if ($area !== null) {
            $project->area()->associate($area);
        }

        $project->save();

        return $project;
    }
}
