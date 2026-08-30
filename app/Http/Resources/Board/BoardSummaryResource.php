<?php

namespace App\Http\Resources\Board;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoardSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $counts = $this->relationLoaded('stages')
            ? $this->stages->mapWithKeys(fn ($stage) => [$stage->key->value => $stage->tasks_count ?? $stage->tasks->count()])
            : collect();

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'position' => $this->position,
            'task_count' => $this->tasks_count ?? $counts->sum(),
            'stage_counts' => [
                'backlog' => (int) $counts->get('backlog', 0),
                'todos' => (int) $counts->get('todos', 0),
                'in_progress' => (int) $counts->get('in_progress', 0),
                'done' => (int) $counts->get('done', 0),
            ],
        ];
    }
}
