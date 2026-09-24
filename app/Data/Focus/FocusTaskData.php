<?php

namespace App\Data\Focus;

use App\Models\FocusTask;
use Spatie\LaravelData\Data;

class FocusTaskData extends Data
{
    public function __construct(
        public string $uuid,
        public string $title,
        public bool $linked,
        public ?array $source,
        public ?string $completed_at,
        public int $session_count,
        public int $position,
    ) {}

    public static function fromModel(FocusTask $task): self
    {
        $boardTask = $task->boardTask;
        $board = $boardTask?->board;
        $project = $board?->context;

        return new self(
            uuid: $task->uuid,
            title: $boardTask?->title ?? $task->title,
            linked: $boardTask !== null,
            source: $boardTask ? [
                'task_uuid' => $boardTask->uuid,
                'project' => $project?->name,
                'board' => $board?->name,
                'stage' => $boardTask->stage?->name,
            ] : null,
            completed_at: $task->completed_at?->toISOString(),
            session_count: (int) ($task->completed_focus_sessions_count ?? 0),
            position: $task->position,
        );
    }
}
