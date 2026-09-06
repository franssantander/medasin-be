<?php

namespace App\Http\Resources\Focus;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FocusSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'focus_minutes' => $this->focus_minutes,
            'short_break_minutes' => $this->short_break_minutes,
            'long_break_minutes' => $this->long_break_minutes,
            'sessions_before_long_break' => $this->sessions_before_long_break,
            'ask_before_next_session' => $this->ask_before_next_session,
            'ask_for_reflection' => $this->ask_for_reflection,
            'ambient_sound' => $this->ambient_sound,
        ];
    }
}
