<?php

namespace Tests\Feature\Project;

use App\Enum\BoardStageKey;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProjectSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_projects_with_default_badge_backgrounds_and_kanban_boards(): void
    {
        Storage::fake('public');
        $this->seed(DemoUserSeeder::class);

        $projects = User::where('email', 'test@example.com')
            ->firstOrFail()
            ->projects()
            ->with(['boards.stages', 'boards.tasks.stage'])
            ->orderBy('name')
            ->get();

        $this->assertSame([
            '10K Training Plan',
            'Annual Reading List',
            'Emergency Fund Plan',
            'Inbox Project',
            'Mindfulness Practice',
            'Portfolio Refresh',
            'Product Release Roadmap',
            'Small Business Growth Plan',
        ], $projects->pluck('name')->all());

        $expectedProgress = [
            '10K Training Plan' => ['tasks' => 4, 'done' => 0, 'progress' => 0, 'status' => 'in_progress'],
            'Annual Reading List' => ['tasks' => 4, 'done' => 2, 'progress' => 50, 'status' => 'in_progress'],
            'Emergency Fund Plan' => ['tasks' => 5, 'done' => 1, 'progress' => 20, 'status' => 'in_progress'],
            'Inbox Project' => ['tasks' => 0, 'done' => 0, 'progress' => 0, 'status' => 'not_started'],
            'Mindfulness Practice' => ['tasks' => 4, 'done' => 4, 'progress' => 100, 'status' => 'completed'],
            'Portfolio Refresh' => ['tasks' => 4, 'done' => 2, 'progress' => 50, 'status' => 'in_progress'],
            'Product Release Roadmap' => ['tasks' => 5, 'done' => 4, 'progress' => 80, 'status' => 'in_progress'],
            'Small Business Growth Plan' => ['tasks' => 5, 'done' => 1, 'progress' => 20, 'status' => 'in_progress'],
        ];

        foreach ($projects as $project) {
            $this->assertSame('#000000', $project->background);
            $this->assertCount(1, $project->boards);
            $this->assertSame('Board 1', $project->boards->first()->name);
            $this->assertSame(
                ['backlog', 'todos', 'in_progress', 'done'],
                $project->boards->first()->stages->pluck('key')->map->value->all(),
            );

            $tasks = $project->boards->first()->tasks;
            $doneTasks = $tasks->filter(fn ($task) => $task->stage->key === BoardStageKey::DONE);
            $expected = $expectedProgress[$project->name];

            $this->assertCount($expected['tasks'], $tasks);
            $this->assertCount($expected['done'], $doneTasks);
            $this->assertSame(
                $expected['progress'],
                $tasks->isEmpty() ? 0 : (int) round(($doneTasks->count() / $tasks->count()) * 100),
            );
            $this->assertTrue($tasks->every(fn ($task) => filled($task->description)));

            foreach ($project->boards->first()->stages as $stage) {
                $positions = $tasks->where('board_stage_id', $stage->getKey())
                    ->sortBy('position')
                    ->pluck('position')
                    ->values()
                    ->all();

                $this->assertSame(array_keys($positions), $positions);
            }
        }

        $this->assertSame(
            'Run final acceptance testing',
            $projects->firstWhere('name', 'Product Release Roadmap')
                ->boards->first()->tasks->firstWhere('stage.key.value', 'in_progress')?->title,
        );

        $user = User::where('email', 'test@example.com')->firstOrFail();
        Passport::actingAs($user);
        $projectCards = collect($this->getJson(route('project.index'))->assertOk()->json('data'))
            ->keyBy('name');

        foreach ($expectedProgress as $projectName => $expected) {
            $this->assertSame($expected['progress'], $projectCards[$projectName]['progress_percentage']);
            $this->assertSame($expected['status'], $projectCards[$projectName]['status']);
        }
    }
}
