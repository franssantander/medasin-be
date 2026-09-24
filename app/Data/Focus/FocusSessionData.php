<?php

namespace App\Data\Focus;

use App\Enum\FocusSessionStatus;
use App\Models\FocusSession;
use Spatie\LaravelData\Data;

class FocusSessionData extends Data
{
    public function __construct(
        public string $uuid,
        public string $type,
        public string $status,
        public int $duration_seconds,
        public int $remaining_seconds,
        public ?array $task,
        public ?string $started_at,
        public ?string $ends_at,
        public string $server_now,
        public ?string $completed_at,
        public ?string $mood,
        public ?string $reflection_note,
    ) {}

    public static function fromModel(FocusSession $session): self
    {
        $remaining = $session->remaining_seconds;
        if ($session->status === FocusSessionStatus::RUNNING && $session->ends_at) {
            $remaining = max(0, (int) ceil(now()->diffInSeconds($session->ends_at, false)));
        }

        return new self(
            uuid: $session->uuid,
            type: $session->type->value,
            status: $session->status->value,
            duration_seconds: $session->duration_seconds,
            remaining_seconds: $remaining,
            task: $session->focusTask ? [
                'uuid' => $session->focusTask->uuid,
                'title' => $session->focusTask->boardTask?->title ?? $session->task_title,
            ] : null,
            started_at: $session->started_at?->toISOString(),
            ends_at: $session->ends_at?->toISOString(),
            server_now: now()->toISOString(),
            completed_at: $session->completed_at?->toISOString(),
            mood: $session->mood?->value,
            reflection_note: $session->reflection_note,
        );
    }
}
