<?php

namespace App\Http\Resources\Focus;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FocusTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $boardTask = $this->boardTask;
        $board = $boardTask?->board;
        $project = $board?->context;

        return [
            'uuid' => $this->uuid,
            'title' => $boardTask?->title ?? $this->title,
            'linked' => $boardTask !== null,
            'source' => $boardTask ? [
                'task_uuid' => $boardTask->uuid,
                'project' => $project?->name,
                'board' => $board?->name,
                'stage' => $boardTask->stage?->name,
            ] : null,
            'completed_at' => $this->completed_at?->toISOString(),
            'session_count' => (int) ($this->completed_focus_sessions_count ?? 0),
            'position' => $this->position,
        ];
    }
}
