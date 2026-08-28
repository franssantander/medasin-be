<?php

namespace App\Models;

use App\Enum\HabitFrequency;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'icon', 'description', 'frequency', 'schedule', 'is_active'])]
class Habit extends Model
{
    use HasUuid, SoftDeletes;

    protected $attributes = [
        'icon' => 'Repeat2',
        'frequency' => HabitFrequency::DAILY->value,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'frequency' => HabitFrequency::class,
            'schedule' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(HabitCheckIn::class);
    }
}
