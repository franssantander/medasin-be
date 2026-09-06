<?php

namespace App\Http\Resources\Focus;

use App\Enum\FocusSessionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FocusSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $remaining = $this->remaining_seconds;
        if ($this->status === FocusSessionStatus::RUNNING && $this->ends_at) {
            $remaining = max(0, (int) ceil(now()->diffInSeconds($this->ends_at, false)));
        }

        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'duration_seconds' => $this->duration_seconds,
            'remaining_seconds' => $remaining,
            'task' => $this->focusTask ? [
                'uuid' => $this->focusTask->uuid,
                'title' => $this->focusTask->boardTask?->title ?? $this->task_title,
            ] : null,
            'started_at' => $this->started_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'server_now' => now()->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'mood' => $this->mood?->value,
            'reflection_note' => $this->reflection_note,
        ];
    }
}
