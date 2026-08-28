<?php

namespace App\Models;

use App\Enum\GoalStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title', 'icon', 'description', 'status', 'start_date', 'due_date', 'completed_at'])]
class Goal extends Model
{
    use HasUuid, SoftDeletes;

    protected $attributes = [
        'icon' => 'Target',
        'status' => GoalStatus::PENDING->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => GoalStatus::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
