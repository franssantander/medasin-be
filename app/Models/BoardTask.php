<?php

namespace App\Models;

use App\Enum\BoardTaskPriority;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title', 'description', 'priority', 'position'])]
class BoardTask extends Model
{
    use HasUuid, SoftDeletes;

    protected function casts(): array
    {
        return ['priority' => BoardTaskPriority::class];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(BoardStage::class, 'board_stage_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(BoardLabel::class, 'board_label_task');
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'board_task_resource');
    }

    public function notes(): BelongsToMany
    {
        return $this->belongsToMany(Note::class, 'board_task_note');
    }
}
