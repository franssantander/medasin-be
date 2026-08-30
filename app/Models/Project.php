<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'slug',
    'description',
    'icon',
    'background',
    'status',
    'start_date',
    'due_date',
    'completed_at',
    'archived_at',
])]
class Project extends Model
{
    use HasUuid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->slug = Str::slug($project->name);
        });

        static::updating(function (Project $project): void {
            if ($project->isDirty('name')) {
                $project->slug = Str::slug($project->name);
            }
        });
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class)
            ->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function boards(): MorphMany
    {
        return $this->morphMany(Board::class, 'context')->orderBy('position');
    }

    public function boardTasks(): HasManyThrough
    {
        return $this->hasManyThrough(
            BoardTask::class,
            Board::class,
            'context_id',
            'board_id',
        )->where('boards.context_type', $this->getMorphClass());
    }
}
