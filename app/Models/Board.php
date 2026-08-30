<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'position'])]
class Board extends Model
{
    use HasUuid, SoftDeletes;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function stages(): HasMany
    {
        return $this->hasMany(BoardStage::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(BoardTask::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(BoardLabel::class)->orderBy('name');
    }
}
