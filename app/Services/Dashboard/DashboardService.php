<?php

namespace App\Services\Dashboard;

use App\Enum\Status;
use App\Models\Area;
use App\Models\Project;
use App\Models\User;
use App\Services\Habit\HabitStreakService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private readonly HabitStreakService $habitStreaks,
        private readonly RecentResourceService $recentResources,
    ) {}

    public function show(User $user, string $timezone): array
    {
        return [
            'stats' => [
                'active_projects' => $user->projects()
                    ->where('status', Status::ACTIVE->value)
                    ->whereNull('archived_at')
                    ->count(),
                'areas' => $user->areas()->whereNull('archived_at')->count(),
                'resources_saved' => $user->resources()->whereNull('archived_at')->count(),
                'habit_streak' => $this->habitStreaks->longestCurrentStreak($user, $timezone),
            ],
            'projects' => $this->projects($user),
            'areas' => $this->areas($user),
            'recent_resources' => $this->recentResources->forUser($user),
            'archives' => [
                'projects' => $user->projects()->whereNotNull('archived_at')->count(),
                'areas' => $user->areas()->whereNotNull('archived_at')->count(),
                'resources' => $user->resources()->whereNotNull('archived_at')->count(),
            ],
        ];
    }

    private function projects(User $user): array
    {
        $projectMorphType = (new Project)->getMorphClass();
        $boardActivity = DB::table('boards')
            ->select('context_id')
            ->selectRaw('MAX(updated_at) AS last_board_activity_at')
            ->where('user_id', $user->getKey())
            ->where('context_type', $projectMorphType)
            ->whereNull('deleted_at')
            ->groupBy('context_id');
        $taskActivity = DB::table('board_tasks')
            ->join('boards', 'boards.id', '=', 'board_tasks.board_id')
            ->select('boards.context_id')
            ->selectRaw('MAX(board_tasks.updated_at) AS last_task_activity_at')
            ->where('boards.user_id', $user->getKey())
            ->where('boards.context_type', $projectMorphType)
            ->whereNull('boards.deleted_at')
            ->whereNull('board_tasks.deleted_at')
            ->groupBy('boards.context_id');

        return $user->projects()
            ->whereNull('projects.archived_at')
            ->leftJoinSub($boardActivity, 'board_activity', fn ($join) => $join
                ->on('projects.id', '=', 'board_activity.context_id'))
            ->leftJoinSub($taskActivity, 'task_activity', fn ($join) => $join
                ->on('projects.id', '=', 'task_activity.context_id'))
            ->select(['projects.id', 'projects.uuid', 'projects.name', 'projects.icon', 'projects.area_id', 'projects.updated_at'])
            ->selectRaw(<<<'SQL'
                CASE
                    WHEN task_activity.last_task_activity_at IS NOT NULL
                        AND task_activity.last_task_activity_at >= projects.updated_at
                        AND (board_activity.last_board_activity_at IS NULL
                            OR task_activity.last_task_activity_at >= board_activity.last_board_activity_at)
                        THEN task_activity.last_task_activity_at
                    WHEN board_activity.last_board_activity_at IS NOT NULL
                        AND board_activity.last_board_activity_at >= projects.updated_at
                        THEN board_activity.last_board_activity_at
                    ELSE projects.updated_at
                END AS last_activity_at
                SQL)
            ->withKanbanCounts()
            ->with(['area' => fn ($areas) => $areas
                ->whereNull('archived_at')
                ->select(['id', 'uuid', 'name'])])
            ->orderByDesc('last_activity_at')
            ->orderByDesc('projects.id')
            ->limit(6)
            ->get()
            ->map(function (Project $project): array {
                $totalTasks = (int) $project->total_tasks_count;
                $completedTasks = (int) $project->done_tasks_count;

                return [
                    'uuid' => $project->uuid,
                    'name' => $project->name,
                    'icon' => $project->icon,
                    'area' => $project->area ? [
                        'uuid' => $project->area->uuid,
                        'name' => $project->area->name,
                    ] : null,
                    'completed_tasks' => $completedTasks,
                    'total_tasks' => $totalTasks,
                    'progress_percentage' => $totalTasks === 0
                        ? null
                        : (int) round(($completedTasks / $totalTasks) * 100),
                    'last_activity_at' => CarbonImmutable::parse($project->last_activity_at)->toISOString(),
                ];
            })
            ->all();
    }

    private function areas(User $user): array
    {
        return $user->areas()
            ->whereNull('archived_at')
            ->select(['id', 'uuid', 'name', 'icon', 'created_at'])
            ->withCount([
                'goals',
                'habits' => fn (Builder $habits): Builder => $habits->where('user_id', $user->getKey()),
                'projects' => fn (Builder $projects): Builder => $projects
                    ->where('user_id', $user->getKey())
                    ->whereNull('archived_at'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Area $area): array => [
                'uuid' => $area->uuid,
                'name' => $area->name,
                'icon' => $area->icon,
                'goals_count' => (int) $area->goals_count,
                'habits_count' => (int) $area->habits_count,
                'projects_count' => (int) $area->projects_count,
            ])
            ->all();
    }
}
