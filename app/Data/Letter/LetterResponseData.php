<?php

namespace App\Data\Letter;

use App\Models\Letter;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class LetterResponseData extends Data
{
    /** @param  array<string, string>|null  $author */
    public function __construct(
        public string $uuid,
        public string $title,
        public ?string $subtitle,
        public string $content_preview,
        public int $word_count,
        public int $read_time_minutes,
        public ?string $status,
        public ?string $exported_at,
        public ?string $created_at,
        public ?string $updated_at,
        public ?array $author,
        public ?array $latest_export,
        public string|Optional $content,
    ) {}

    public static function fromModel(Letter $letter, bool $includeContent = false): self
    {
        $user = $letter->relationLoaded('user') ? $letter->user : null;

        return new self(
            uuid: $letter->uuid,
            title: $letter->title,
            subtitle: $letter->subtitle,
            content_preview: Str::limit((string) $letter->content_text, 240, '...'),
            word_count: $letter->word_count,
            read_time_minutes: $letter->read_time_minutes,
            status: $letter->status?->value,
            exported_at: $letter->exported_at?->toISOString(),
            created_at: $letter->created_at?->toISOString(),
            updated_at: $letter->updated_at?->toISOString(),
            author: $user ? [
                'name' => trim($user->first_name.' '.$user->last_name),
                'handle' => '@'.$user->username,
            ] : null,
            latest_export: $letter->relationLoaded('latestExport') && $letter->latestExport
                ? LetterExportData::fromModel($letter->latestExport)->toArray()
                : null,
            content: $includeContent ? $letter->content : Optional::create(),
        );
    }
}
