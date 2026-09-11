<?php

namespace App\Http\Resources\Letter;

use App\Enum\LetterExportStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LetterExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $letter = $this->relationLoaded('letter') ? $this->letter : null;

        return [
            'uuid' => $this->uuid,
            'letter_uuid' => $letter?->uuid,
            'format' => $this->format?->value,
            'canvas' => [
                'width' => $this->canvas_width,
                'height' => $this->canvas_height,
            ],
            'status' => $this->status?->value,
            'is_current' => $letter !== null
                && hash_equals($this->source_hash, $letter->sourceHash()),
            'page_count' => $this->page_count,
            'pages' => $this->pages,
            'error' => $this->status === LetterExportStatus::FAILED ? $this->error_message : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
