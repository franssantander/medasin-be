<?php

namespace App\Models;

use App\Enum\FocusMood;
use App\Enum\FocusSessionStatus;
use App\Enum\FocusSessionType;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['task_title', 'type', 'status', 'duration_seconds', 'remaining_seconds', 'started_at', 'ends_at', 'paused_at', 'completed_at', 'cancelled_at', 'mood', 'reflection_note'])]
class FocusSession extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'type' => FocusSessionType::class,
            'status' => FocusSessionStatus::class,
            'mood' => FocusMood::class,
            'duration_seconds' => 'integer',
            'remaining_seconds' => 'integer',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function focusTask(): BelongsTo
    {
        return $this->belongsTo(FocusTask::class)->withTrashed();
    }

    public function journalEntry(): HasOne
    {
        return $this->hasOne(JournalEntry::class);
    }
}
