<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'subject_type',
    'subject_id',
    'subject_uuid',
    'item_type',
    'title',
    'context',
    'metadata',
    'deleted_at',
    'expires_at',
])]
class TrashEntry extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'deleted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
