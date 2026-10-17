<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['path', 'original_name', 'mime_type', 'size'])]
class LetterMedia extends Model
{
    use HasUuid;

    /** Private: files are served only to their owner through letters.media.show. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }

    /** Relative, so the browser requests it through the frontend's /api/v1 proxy and sends the auth cookie. */
    public function url(): string
    {
        return route('letters.media.show', $this, absolute: false);
    }
}
