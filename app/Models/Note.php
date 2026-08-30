<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['parent_id', 'title', 'content', 'is_pinned'])]
class Note extends Model
{
    use HasUuid, SoftDeletes;

    protected $appends = ['parent_uuid'];

    protected $hidden = ['parent_id'];

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

    public function getParentUuidAttribute(): ?string
    {
        if (! $this->parent_id) {
            return null;
        }

        return $this->relationLoaded('parent')
            ? $this->parent?->uuid
            : self::query()->whereKey($this->parent_id)->value('uuid');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(NoteMedia::class);
    }

    public function boardTasks(): BelongsToMany
    {
        return $this->belongsToMany(BoardTask::class, 'board_task_note');
    }
}
