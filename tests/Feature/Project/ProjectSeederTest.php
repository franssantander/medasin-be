<?php

namespace Tests\Feature\Project;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
            ->with('boards.stages')
            ->orderBy('name')
            ->get();

        $this->assertSame([
            '10K Training Plan',
            'Annual Reading List',
            'Inbox Project',
            'Portfolio Refresh',
        ], $projects->pluck('name')->all());

        foreach ($projects as $project) {
            $this->assertSame('#000000', $project->background);
            $this->assertCount(1, $project->boards);
            $this->assertSame('Board 1', $project->boards->first()->name);
            $this->assertSame(
                ['backlog', 'todos', 'in_progress', 'done'],
                $project->boards->first()->stages->pluck('key')->map->value->all(),
            );
        }
    }
}
