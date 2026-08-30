<?php

namespace App\Models;

use App\Enum\BoardStageKey;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'position'])]
class BoardStage extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return ['key' => BoardStageKey::class];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(BoardTask::class)->orderBy('position');
    }
}
