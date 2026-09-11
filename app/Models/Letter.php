<?php

namespace App\Models;

use App\Enum\LetterStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\LetterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title',
    'subtitle',
    'content',
    'content_text',
    'word_count',
    'read_time_minutes',
    'status',
    'exported_at',
])]
class Letter extends Model
{
    /** @use HasFactory<LetterFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected $attributes = [
        'status' => LetterStatus::DRAFT->value,
        'word_count' => 0,
        'read_time_minutes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => LetterStatus::class,
            'word_count' => 'integer',
            'read_time_minutes' => 'integer',
            'exported_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(LetterExport::class);
    }

    public function letterExports(): HasMany
    {
        return $this->exports();
    }

    public function latestExport(): HasOne
    {
        return $this->hasOne(LetterExport::class)->latestOfMany();
    }

    public function sourceHash(): string
    {
        return hash('sha256', json_encode([
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'content' => $this->content,
        ], JSON_THROW_ON_ERROR));
    }
}
