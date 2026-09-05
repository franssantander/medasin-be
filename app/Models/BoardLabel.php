<?php

namespace App\Models;

use App\Enum\BoardLabelColor;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'color'])]
class BoardLabel extends Model
{
    use HasUuid, SoftDeletes;

    protected function casts(): array
    {
        return ['color' => BoardLabelColor::class];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(BoardTask::class, 'board_label_task');
    }
}
