<?php

namespace App\Http\Resources\Journal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class JournalEntryResource extends JsonResource
{
    private bool $includeContent = false;

    public function withContent(bool $includeContent = true): static
    {
        $this->includeContent = $includeContent;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'content_preview' => Str::limit((string) $this->content_text, 240, '...'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'resources' => $this->relationLoaded('resources')
                ? $this->resources->map(fn ($resource): array => [
                    'uuid' => $resource->uuid,
                    'title' => $resource->title,
                    'type' => $resource->type,
                    'archived_at' => $resource->archived_at?->toISOString(),
                ])->values()->all()
                : [],
            'source' => $this->source(),
        ];

        if ($this->includeContent) {
            $data['content'] = $this->content;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function source(): ?array
    {
        if (! $this->relationLoaded('focusSession') || ! $this->focusSession) {
            return null;
        }

        return [
            'type' => 'focus_reflection',
            'session_uuid' => $this->focusSession->uuid,
            'task_title' => $this->focusSession->task_title,
            'completed_at' => $this->focusSession->completed_at?->toISOString(),
            'mood' => $this->focusSession->mood?->value,
        ];
    }
}
