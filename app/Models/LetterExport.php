<?php

namespace App\Models;

use App\Enum\LetterExportFormat;
use App\Enum\LetterExportStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\LetterExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'letter_id',
    'format',
    'canvas_width',
    'canvas_height',
    'source_hash',
    'status',
    'pages',
    'page_count',
    'error_message',
    'started_at',
    'completed_at',
])]
class LetterExport extends Model
{
    /** @use HasFactory<LetterExportFactory> */
    use HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'format' => LetterExportFormat::class,
            'status' => LetterExportStatus::class,
            'canvas_width' => 'integer',
            'canvas_height' => 'integer',
            'pages' => 'array',
            'page_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }
}
