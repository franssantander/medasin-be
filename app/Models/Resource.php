<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title',
    'type',
    'description',
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
            'is_favorite' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withTimestamps();
    }

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class)
            ->withTimestamps();
    }
}