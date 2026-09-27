<?php

namespace Tests\Feature\Dashboard;

use App\Enum\BoardStageKey;
use App\Models\Area;
use App\Models\Board;
use App\Models\BoardTask;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ShowDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_when_no_api_token_is_present(): void
    {
        $this->getJson(route('dashboard.show'))->assertUnauthorized();
    }

    public function test_returns_zero_counts_and_empty_sections_for_a_new_user(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson(route('dashboard.show'))
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.stats', [
                'active_projects' => 0,
                'areas' => 0,
                'resources_saved' => 0,
                'habit_streak' => 0,
            ])
            ->assertJsonPath('data.projects', [])
            ->assertJsonPath('data.areas', [])
            ->assertJsonPath('data.recent_resources', [])
            ->assertJsonPath('data.archives', [
                'projects' => 0,
                'areas' => 0,
                'resources' => 0,
            ]);
    }

    public function test_counts_only_owned_active_records_and_separates_archives_from_trash(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Work']);
        $user->areas()->create(['name' => 'Archived area', 'archived_at' => now()]);
        $deletedArea = $user->areas()->create(['name' => 'Deleted area']);
        $deletedArea->delete();
        $otherUser->areas()->create(['name' => 'Private area']);

        $active = $this->createProject($user, 'Active project', [], $area);
        $inactive = $this->createProject($user, 'Inactive project', ['status' => 'inactive'], $area);
        $this->createProject($user, 'Archived project', ['archived_at' => now()]);
        $deletedProject = $this->createProject($user, 'Deleted project');
        $deletedProject->delete();
        $this->createProject($otherUser, 'Private project');

        $resource = $user->resources()->create(['title' => 'Saved topic', 'content' => ['type' => 'doc']]);
        $resource->attachments()->create(['kind' => 'link', 'url' => 'https://example.com/saved']);
        $user->resources()->create(['title' => 'Archived topic', 'archived_at' => now()]);
        $deletedResource = $user->resources()->create(['title' => 'Deleted topic']);
        $deletedResource->delete();
        $otherUser->resources()->create(['title' => 'Private topic', 'content' => ['type' => 'doc']]);
        Passport::actingAs($user);

        $response = $this->getJson(route('dashboard.show'))
            ->assertOk()
            ->assertJsonPath('data.stats.active_projects', 1)
            ->assertJsonPath('data.stats.areas', 1)
            ->assertJsonPath('data.stats.resources_saved', 1)
            ->assertJsonPath('data.archives', ['projects' => 1, 'areas' => 1, 'resources' => 1])
            ->assertJsonCount(2, 'data.projects')
            ->assertJsonCount(1, 'data.areas')
            ->assertJsonPath('data.areas.0.uuid', $area->uuid)
            ->assertJsonMissing(['uuid' => $deletedProject->uuid])
            ->assertJsonMissing(['title' => 'Private topic']);

        $projectUuids = collect($response->json('data.projects'))->pluck('uuid')->sort()->values()->all();
        $expectedUuids = collect([$active->uuid, $inactive->uuid])->sort()->values()->all();
        $this->assertSame($expectedUuids, $projectUuids);
    }

    public function test_returns_six_projects_ordered_by_latest_project_board_or_task_activity(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Planning']);
        $projects = [];

        $this->travelTo(CarbonImmutable::parse('2026-09-01 12:00:00 UTC'));
        $projects[1] = $this->createProject($user, 'Project 1', ['status' => 'inactive'], $area);
        $firstBoard = $this->createBoard($user, $projects[1], 'First board', 0);
        $secondBoard = $this->createBoard($user, $projects[1], 'Second board', 1);
        $deletedBoard = $this->createBoard($user, $projects[1], 'Deleted board', 2);
        $updatedTask = $this->createTask($firstBoard, BoardStageKey::DONE, 'Finished task');
        $this->createTask($firstBoard, BoardStageKey::BACKLOG, 'Open task');
        $this->createTask($secondBoard, BoardStageKey::DONE, 'Another finished task');
        $deletedTask = $this->createTask($secondBoard, BoardStageKey::DONE, 'Deleted task');
        $deletedTask->delete();
        $this->createTask($deletedBoard, BoardStageKey::DONE, 'Task on deleted board');
        $deletedBoard->delete();

        foreach (range(2, 7) as $day) {
            $this->travelTo(CarbonImmutable::parse(sprintf('2026-09-%02d 12:00:00 UTC', $day)));
            $projects[$day] = $this->createProject($user, "Project {$day}");
        }

        $completedBoard = $this->createBoard($user, $projects[7], 'Completed board', 0);
        $this->createTask($completedBoard, BoardStageKey::DONE, 'Completed work');

        $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00 UTC'));
        $updatedTask->update(['title' => 'Recently updated task']);
        Passport::actingAs($user);

        $response = $this->getJson(route('dashboard.show'))
            ->assertOk()
            ->assertJsonCount(6, 'data.projects')
            ->assertJsonPath('data.stats.active_projects', 6)
            ->assertJsonPath('data.projects.0.uuid', $projects[1]->uuid)
            ->assertJsonPath('data.projects.0.name', 'Project 1')
            ->assertJsonPath('data.projects.0.area.uuid', $area->uuid)
            ->assertJsonPath('data.projects.0.area.name', 'Planning')
            ->assertJsonPath('data.projects.0.completed_tasks', 2)
            ->assertJsonPath('data.projects.0.total_tasks', 3)
            ->assertJsonPath('data.projects.0.progress_percentage', 67)
            ->assertJsonPath('data.projects.1.uuid', $projects[7]->uuid)
            ->assertJsonPath('data.projects.1.area', null)
            ->assertJsonPath('data.projects.1.progress_percentage', 100)
            ->assertJsonPath('data.projects.2.uuid', $projects[6]->uuid)
            ->assertJsonPath('data.projects.2.progress_percentage', null);

        $projectUuids = collect($response->json('data.projects'))->pluck('uuid')->all();
        $this->assertNotContains($projects[2]->uuid, $projectUuids);
        $this->assertSame('2026-09-10', substr($response->json('data.projects.0.last_activity_at'), 0, 10));
    }

    public function test_area_cards_count_non_deleted_goals_habits_and_unarchived_projects(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Health', 'icon' => 'Heart']);
        $area->goals()->create(['title' => 'Run']);
        $area->goals()->create(['title' => 'Sleep']);
        $deletedGoal = $area->goals()->create(['title' => 'Deleted goal']);
        $deletedGoal->delete();
        $user->habits()->create(['name' => 'Walk', 'area_id' => $area->id]);
        $user->habits()->create(['name' => 'Stretch', 'area_id' => $area->id, 'is_active' => false]);
        $deletedHabit = $user->habits()->create(['name' => 'Deleted habit', 'area_id' => $area->id]);
        $deletedHabit->delete();
        $this->createProject($user, 'Active', [], $area);
        $this->createProject($user, 'Inactive', ['status' => 'inactive'], $area);
        $this->createProject($user, 'Archived', ['archived_at' => now()], $area);
        $deletedProject = $this->createProject($user, 'Deleted', [], $area);
        $deletedProject->delete();
        Passport::actingAs($user);

        $this->getJson(route('dashboard.show'))
            ->assertOk()
            ->assertJsonPath('data.areas.0.uuid', $area->uuid)
            ->assertJsonPath('data.areas.0.name', 'Health')
            ->assertJsonPath('data.areas.0.icon', 'Heart')
            ->assertJsonPath('data.areas.0.goals_count', 2)
            ->assertJsonPath('data.areas.0.habits_count', 2)
            ->assertJsonPath('data.areas.0.projects_count', 2);
    }

    public function test_recent_resources_merge_note_attachment_and_legacy_items_by_recency(): void
    {
        $user = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00 UTC'));
        $topic = $user->resources()->create([
            'title' => 'Research notes',
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00 UTC'));
        $topic->attachments()->create(['kind' => 'link', 'url' => 'https://example.com/article']);
        $this->travelTo(CarbonImmutable::parse('2026-09-22 12:00:00 UTC'));
        $topic->attachments()->create(['kind' => 'file', 'original_name' => 'outline.pdf', 'path' => 'resources/outline.pdf']);
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00 UTC'));
        $legacy = $user->resources()->create([
            'title' => 'Old-style bookmark',
            'type' => 'link',
            'url' => 'https://example.com/old',
        ]);
        $user->resources()->create(['title' => 'Empty topic']);
        $archived = $user->resources()->create(['title' => 'Archived topic', 'archived_at' => now()]);
        $archived->attachments()->create(['kind' => 'link', 'url' => 'https://example.com/archived']);
        $deleted = $user->resources()->create(['title' => 'Deleted topic', 'content' => ['type' => 'doc']]);
        $deleted->delete();
        Passport::actingAs($user);

        $response = $this->getJson(route('dashboard.show'))
            ->assertOk()
            ->assertJsonPath('data.stats.resources_saved', 3)
            ->assertJsonCount(4, 'data.recent_resources')
            ->assertJsonPath('data.recent_resources.0.type', 'link')
            ->assertJsonPath('data.recent_resources.0.title', 'https://example.com/old')
            ->assertJsonPath('data.recent_resources.0.resource_uuid', $legacy->uuid)
            ->assertJsonPath('data.recent_resources.1.type', 'file')
            ->assertJsonPath('data.recent_resources.1.title', 'outline.pdf')
            ->assertJsonPath('data.recent_resources.1.resource_uuid', $topic->uuid)
            ->assertJsonPath('data.recent_resources.2.type', 'link')
            ->assertJsonPath('data.recent_resources.2.resource_uuid', $topic->uuid)
            ->assertJsonPath('data.recent_resources.3.type', 'note')
            ->assertJsonPath('data.recent_resources.3.title', 'Research notes')
            ->assertJsonPath('data.recent_resources.3.resource_uuid', $topic->uuid);

        $items = $response->json('data.recent_resources');
        $this->assertCount(4, array_unique(array_column($items, 'item_key')));
        $this->assertSame(
            ['2026-09-23', '2026-09-22', '2026-09-21', '2026-09-20'],
            array_map(fn (array $item): string => substr($item['occurred_at'], 0, 10), $items),
        );
    }

    public function test_recent_resources_return_only_the_five_newest_items(): void
    {
        $user = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-09-01 12:00:00 UTC'));
        $topic = $user->resources()->create(['title' => 'Bookmarks']);

        foreach (range(1, 7) as $day) {
            $this->travelTo(CarbonImmutable::parse(sprintf('2026-09-%02d 12:00:00 UTC', $day)));
            $topic->attachments()->create(['kind' => 'link', 'url' => "https://example.com/{$day}"]);
        }

        Passport::actingAs($user);

        $response = $this->getJson(route('dashboard.show'))
            ->assertOk()
            ->assertJsonCount(5, 'data.recent_resources');

        $this->assertSame(
            ['2026-09-07', '2026-09-06', '2026-09-05', '2026-09-04', '2026-09-03'],
            array_map(fn (array $item): string => substr($item['occurred_at'], 0, 10), $response->json('data.recent_resources')),
        );
    }

    public function test_habit_streak_uses_the_longest_active_scheduled_completion_run(): void
    {
        $user = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-09-01 12:00:00 UTC'));
        $weekly = $user->habits()->create([
            'name' => 'Weekly review',
            'frequency' => 'weekly',
            'schedule' => ['days' => ['monday']],
        ]);
        foreach (['2026-09-07', '2026-09-14', '2026-09-21'] as $date) {
            $weekly->checkIns()->create(['check_in_date' => $date, 'completed' => true]);
        }
        $shorter = $user->habits()->create(['name' => 'Daily walk']);
        $shorter->checkIns()->create(['check_in_date' => '2026-09-27', 'completed' => true]);
        $paused = $user->habits()->create(['name' => 'Paused', 'is_active' => false]);
        $deleted = $user->habits()->create(['name' => 'Deleted']);
        foreach (['2026-09-25', '2026-09-26', '2026-09-27', '2026-09-28'] as $date) {
            $paused->checkIns()->create(['check_in_date' => $date, 'completed' => true]);
            $deleted->checkIns()->create(['check_in_date' => $date, 'completed' => true]);
        }
        $deleted->delete();
        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00 UTC'));
        Passport::actingAs($user);

        $this->getJson(route('dashboard.show'))
            ->assertOk()
            ->assertJsonPath('data.stats.habit_streak', 3);
    }

    public function test_explicit_missed_check_in_today_breaks_the_current_streak(): void
    {
        $user = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00:00 UTC'));
        $habit = $user->habits()->create(['name' => 'Read']);
        $habit->checkIns()->create(['check_in_date' => '2026-09-26', 'completed' => true]);
        $habit->checkIns()->create(['check_in_date' => '2026-09-27', 'completed' => true]);
        $habit->checkIns()->create(['check_in_date' => '2026-09-28', 'completed' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00 UTC'));
        Passport::actingAs($user);

        $this->getJson(route('dashboard.show'))
            ->assertOk()
            ->assertJsonPath('data.stats.habit_streak', 0);
    }

    public function test_timezone_uses_the_users_local_day_for_habit_streak(): void
    {
        $user = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00:00 UTC'));
        $habit = $user->habits()->create(['name' => 'Read']);
        $habit->checkIns()->create(['check_in_date' => '2026-09-26', 'completed' => true]);
        $habit->checkIns()->create(['check_in_date' => '2026-09-27', 'completed' => true]);
        $habit->checkIns()->create(['check_in_date' => '2026-09-28', 'completed' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-09-27 16:30:00 UTC'));
        Passport::actingAs($user);

        $this->getJson(route('dashboard.show'))
            ->assertOk()
            ->assertJsonPath('data.stats.habit_streak', 2);
        $this->getJson(route('dashboard.show', ['timezone' => 'Asia/Manila']))
            ->assertOk()
            ->assertJsonPath('data.stats.habit_streak', 0);
    }

    public function test_returns_422_for_an_invalid_timezone(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson(route('dashboard.show', ['timezone' => 'Mars/Olympus_Mons']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProject(User $user, string $name, array $attributes = [], ?Area $area = null): Project
    {
        $project = $user->projects()->make(['name' => $name, ...$attributes]);
        if ($area !== null) {
            $project->area()->associate($area);
        }
        $project->save();

        return $project;
    }

    private function createBoard(User $user, Project $project, string $name, int $position): Board
    {
        $board = $project->boards()->make(['name' => $name, 'position' => $position]);
        $board->user()->associate($user);
        $board->save();
        $board->stages()->create(['key' => BoardStageKey::BACKLOG, 'name' => 'Backlog', 'position' => 0]);
        $board->stages()->create(['key' => BoardStageKey::DONE, 'name' => 'Done', 'position' => 1]);

        return $board;
    }

    private function createTask(Board $board, BoardStageKey $stageKey, string $title): BoardTask
    {
        $stage = $board->stages()->where('key', $stageKey->value)->firstOrFail();
        $task = $board->tasks()->make(['title' => $title]);
        $task->stage()->associate($stage);
        $task->save();

        return $task;
    }
}
