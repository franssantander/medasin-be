<?php

namespace App\Data\Letter;

use App\Enum\LetterExportStatus;
use App\Models\LetterExport;
use Spatie\LaravelData\Data;

class LetterExportData extends Data
{
    public function __construct(
        public string $uuid,
        public ?string $letter_uuid,
        public ?string $format,
        public array $canvas,
        public ?string $status,
        public bool $is_current,
        public ?int $page_count,
        public ?array $pages,
        public ?string $error,
        public ?string $created_at,
        public ?string $updated_at,
        public ?string $started_at,
        public ?string $completed_at,
    ) {}

    public static function fromModel(LetterExport $export): self
    {
        $letter = $export->relationLoaded('letter') ? $export->letter : null;

        return self::from([
            'uuid' => $export->uuid,
            'letter_uuid' => $letter?->uuid,
            'format' => $export->format?->value,
            'canvas' => [
                'width' => $export->canvas_width,
                'height' => $export->canvas_height,
            ],
            'status' => $export->status?->value,
            'is_current' => $letter !== null
                && hash_equals($export->source_hash, $letter->sourceHash()),
            'page_count' => $export->page_count,
            'pages' => $export->pages,
            'error' => $export->status === LetterExportStatus::FAILED ? $export->error_message : null,
            'created_at' => $export->created_at?->toISOString(),
            'updated_at' => $export->updated_at?->toISOString(),
            'started_at' => $export->started_at?->toISOString(),
            'completed_at' => $export->completed_at?->toISOString(),
        ]);
    }
}
