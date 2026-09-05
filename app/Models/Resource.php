<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title',
    'type',
    'description',
    'content',
    'content_text',
    'url',
    'author',
    'source',
    'icon',
    'background',
    'is_favorite',
    'archived_at',
])]
class Resource extends Model
{
    use HasUuid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_favorite' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withTimestamps();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ResourceAttachment::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ResourceTag::class);
    }

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class)
            ->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function boardTasks(): BelongsToMany
    {
        return $this->belongsToMany(BoardTask::class, 'board_task_resource');
    }
}
