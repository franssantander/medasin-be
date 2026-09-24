<?php

namespace App\Data\Board;

use App\Models\Board;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class BoardSummaryData extends Data
{
    /**
     * @param  array<string, int>  $stage_counts
     * @param  array<int, array<string, mixed>>|Optional  $stages
     * @param  array<int, array<string, mixed>>|Optional  $labels
     */
    public function __construct(
        public string $uuid,
        public string $name,
        public int $position,
        public int $task_count,
        public array $stage_counts,
        public array|Optional $stages,
        public array|Optional $labels,
    ) {}

    public static function fromModel(Board $board): static
    {
        $counts = $board->relationLoaded('stages')
            ? $board->stages->mapWithKeys(fn ($stage) => [$stage->key->value => $stage->tasks_count ?? $stage->tasks->count()])
            : collect();

        return new static(
            uuid: $board->uuid,
            name: $board->name,
            position: $board->position,
            task_count: $board->tasks_count ?? $counts->sum(),
            stage_counts: [
                'backlog' => (int) $counts->get('backlog', 0),
                'todos' => (int) $counts->get('todos', 0),
                'in_progress' => (int) $counts->get('in_progress', 0),
                'done' => (int) $counts->get('done', 0),
            ],
            stages: Optional::create(),
            labels: Optional::create(),
        );
    }
}
