<?php

namespace App\Http\Resources\Board;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoardTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority->value,
            'stage' => $this->stage->key->value,
            'position' => $this->position,
            'labels' => BoardLabelResource::collection($this->whenLoaded('labels')),
            'resources' => $this->whenLoaded('resources', fn () => $this->resources->map(fn ($resource) => [
                'uuid' => $resource->uuid,
                'title' => $resource->title,
                'type' => $resource->type,
                'areas' => $resource->areas->map(fn ($area) => [
                    'uuid' => $area->uuid,
                    'name' => $area->name,
                ])->values(),
                'created_at' => $resource->created_at?->toISOString(),
                'updated_at' => $resource->updated_at?->toISOString(),
            ])),
            'notes' => $this->whenLoaded('notes', fn () => $this->notes->map(fn ($note) => [
                'uuid' => $note->uuid,
                'title' => $note->title,
                'area' => [
                    'uuid' => $note->area->uuid,
                    'name' => $note->area->name,
                ],
                'created_at' => $note->created_at?->toISOString(),
                'updated_at' => $note->updated_at?->toISOString(),
            ])),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
