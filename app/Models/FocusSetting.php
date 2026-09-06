<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['focus_minutes', 'short_break_minutes', 'long_break_minutes', 'sessions_before_long_break', 'ask_before_next_session', 'ask_for_reflection', 'ambient_sound'])]
class FocusSetting extends Model
{
    protected function casts(): array
    {
        return [
            'focus_minutes' => 'integer',
            'short_break_minutes' => 'integer',
            'long_break_minutes' => 'integer',
            'sessions_before_long_break' => 'integer',
            'ask_before_next_session' => 'boolean',
            'ask_for_reflection' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
