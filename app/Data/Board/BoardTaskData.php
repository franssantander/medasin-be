<?php

namespace App\Data\Board;

use App\Models\BoardTask;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class BoardTaskData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>|Optional  $labels
     * @param  array<int, array<string, mixed>>|Optional  $resources
     * @param  array<int, array<string, mixed>>|Optional  $notes
     */
    public function __construct(
        public string $uuid,
        public string $title,
        public ?string $description,
        public string $priority,
        public string $stage,
        public int $position,
        public array|Optional $labels,
        public array|Optional $resources,
        public array|Optional $notes,
        public ?string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(BoardTask $task): self
    {
        return new self(
            uuid: $task->uuid,
            title: $task->title,
            description: $task->description,
            priority: $task->priority->value,
            stage: $task->stage->key->value,
            position: $task->position,
            labels: $task->relationLoaded('labels')
                ? $task->labels->map(fn ($label): array => BoardLabelData::fromModel($label)->toArray())->all()
                : Optional::create(),
            resources: $task->relationLoaded('resources') ? $task->resources->map(fn ($resource): array => [
                'uuid' => $resource->uuid,
                'title' => $resource->title,
                'type' => $resource->type,
                'areas' => $resource->areas->map(fn ($area) => [
                    'uuid' => $area->uuid,
                    'name' => $area->name,
                ])->values(),
                'created_at' => $resource->created_at?->toISOString(),
                'updated_at' => $resource->updated_at?->toISOString(),
            ])->all() : Optional::create(),
            notes: $task->relationLoaded('notes') ? $task->notes->map(fn ($note): array => [
                'uuid' => $note->uuid,
                'title' => $note->title,
                'area' => $note->area ? [
                    'uuid' => $note->area->uuid,
                    'name' => $note->area->name,
                ] : null,
                'created_at' => $note->created_at?->toISOString(),
                'updated_at' => $note->updated_at?->toISOString(),
            ])->all() : Optional::create(),
            created_at: $task->created_at?->toISOString(),
            updated_at: $task->updated_at?->toISOString(),
        );
    }
}
