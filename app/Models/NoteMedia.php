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

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }
}
