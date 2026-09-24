<?php

namespace App\Data\Board;

use App\Models\Board;
use Spatie\LaravelData\Optional;

class BoardDetailData extends BoardSummaryData
{
    public static function fromModel(Board $board): static
    {
        $data = parent::fromModel($board);
        $data->stages = $board->stages->map(fn ($stage): array => [
            'uuid' => $stage->uuid,
            'key' => $stage->key->value,
            'name' => $stage->name,
            'position' => $stage->position,
            'task_count' => $stage->tasks_count ?? $stage->tasks->count(),
            'tasks' => $stage->tasks->map(fn ($task): array => BoardTaskData::fromModel($task)->toArray())->all(),
        ])->all();
        $data->labels = $board->relationLoaded('labels')
            ? $board->labels->map(fn ($label): array => BoardLabelData::fromModel($label)->toArray())->all()
            : Optional::create();

        return $data;
    }
}
