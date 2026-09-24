<?php

namespace App\Data\Project;

use App\Models\Project;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class ProjectListCardData extends Data
{
    /**
     * @param  array<string, mixed>|null  $area
     * @param  array<string, mixed>  $goals
     * @param  array<int, array<string, mixed>>|Optional  $boards
     */
    public function __construct(
        public string $uuid,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $icon,
        public ?string $background,
        public string $status,
        public int $progress_percentage,
        public ?string $start_date,
        public ?string $due_date,
        public bool $is_overdue,
        public ?int $days_overdue,
        public ?string $archived_at,
        public ?array $area,
        public array $goals,
        public array|Optional $boards,
    ) {}

    public static function fromModel(Project $project): static
    {
        $dueDate = $project->due_date;
        $totalTasks = (int) ($project->total_tasks_count ?? 0);
        $doneTasks = (int) ($project->done_tasks_count ?? 0);
        $status = match (true) {
            $totalTasks === 0 => 'not_started',
            $doneTasks === $totalTasks => 'completed',
            default => 'in_progress',
        };
        $progress = $totalTasks === 0 ? 0 : (int) round(($doneTasks / $totalTasks) * 100);
        $isOverdue = $status !== 'completed' && $dueDate !== null && $dueDate->isBefore(today());

        return new static(
            uuid: $project->uuid,
            name: $project->name,
            slug: $project->slug,
            description: $project->description,
            icon: $project->icon,
            background: $project->background,
            status: $status,
            progress_percentage: $progress,
            start_date: $project->start_date?->toDateString(),
            due_date: $dueDate?->toDateString(),
            is_overdue: $isOverdue,
            days_overdue: $isOverdue ? (int) $dueDate->diffInDays(today()) : null,
            archived_at: $project->archived_at?->toISOString(),
            area: $project->area ? [
                'uuid' => $project->area->uuid,
                'name' => $project->area->name,
                'slug' => $project->area->slug,
                'icon' => $project->area->icon,
            ] : null,
            goals: [
                'count' => $project->area?->goals_count ?? 0,
                'url' => $project->area ? route('area.goals.index', $project->area) : null,
            ],
            boards: Optional::create(),
        );
    }
}
