<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;


#[Fillable([
    'name',
    'slug',
    'icon',
    'background',
    'description',
    'archived_at',
])]
class Area extends Model
{
    use HasUuid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Area $area): void {
            $area->slug = Str::slug($area->name);
        });

        static::updating(function (Area $area): void {
            if ($area->isDirty('name')) {
                $area->slug = Str::slug($area->name);
            }
        });
    }


    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
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
}