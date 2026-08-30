<?php

namespace App\Http\Resources\Board;

use Illuminate\Http\Request;

class BoardResource extends BoardSummaryResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'stages' => $this->stages->map(fn ($stage) => [
                'uuid' => $stage->uuid,
                'key' => $stage->key->value,
                'name' => $stage->name,
                'position' => $stage->position,
                'task_count' => $stage->tasks_count ?? $stage->tasks->count(),
                'tasks' => BoardTaskResource::collection($stage->tasks),
            ]),
            'labels' => BoardLabelResource::collection($this->whenLoaded('labels')),
        ];
    }
}
