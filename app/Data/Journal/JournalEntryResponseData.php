<?php

namespace App\Data\Journal;

use App\Models\JournalEntry;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class JournalEntryResponseData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $resources
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $uuid,
        public string $title,
        public string $content_preview,
        public ?string $created_at,
        public ?string $updated_at,
        public array $resources,
        public ?array $source,
        public string|Optional $content,
    ) {}

    public static function fromModel(JournalEntry $entry, bool $includeContent = false): self
    {
        return new self(
            uuid: $entry->uuid,
            title: $entry->title,
            content_preview: Str::limit((string) $entry->content_text, 240, '...'),
            created_at: $entry->created_at?->toISOString(),
            updated_at: $entry->updated_at?->toISOString(),
            resources: $entry->relationLoaded('resources')
                ? $entry->resources->map(fn ($resource): array => [
                    'uuid' => $resource->uuid,
                    'title' => $resource->title,
                    'type' => $resource->type,
                    'archived_at' => $resource->archived_at?->toISOString(),
                ])->values()->all()
                : [],
            source: self::source($entry),
            content: $includeContent ? $entry->content : Optional::create(),
        );
    }

    /** @return array<string, mixed>|null */
    private static function source(JournalEntry $entry): ?array
    {
        if (! $entry->relationLoaded('focusSession') || ! $entry->focusSession) {
            return null;
        }

        return [
            'type' => 'focus_reflection',
            'session_uuid' => $entry->focusSession->uuid,
            'task_title' => $entry->focusSession->task_title,
            'completed_at' => $entry->focusSession->completed_at?->toISOString(),
            'mood' => $entry->focusSession->mood?->value,
        ];
    }
}
