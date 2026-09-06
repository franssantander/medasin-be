<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title', 'position', 'completed_at'])]
class FocusTask extends Model
{
    use HasUuid, SoftDeletes;

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'position' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function boardTask(): BelongsTo
    {
        return $this->belongsTo(BoardTask::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(FocusSession::class);
    }
}
