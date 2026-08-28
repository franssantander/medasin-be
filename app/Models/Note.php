<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title', 'content', 'is_pinned'])]
class Note extends Model
{
    use HasUuid, SoftDeletes;

    protected $attributes = [
        'is_pinned' => false,
    ];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean'];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
