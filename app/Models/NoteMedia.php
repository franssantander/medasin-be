<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['path', 'original_name', 'mime_type', 'size'])]
class NoteMedia extends Model
{
    use HasUuid;

    /** Private: files are served only to their owner through notes.media.show. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    /** Relative, so the browser requests it through the frontend's /api/v1 proxy and sends the auth cookie. */
    public function url(): string
    {
        return route('notes.media.show', $this, absolute: false);
    }
}
