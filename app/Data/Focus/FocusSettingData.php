<?php

namespace App\Data\Focus;

use App\Models\FocusSetting;
use Spatie\LaravelData\Data;

class FocusSettingData extends Data
{
    public function __construct(
        public int $focus_minutes,
        public int $short_break_minutes,
        public int $long_break_minutes,
        public int $sessions_before_long_break,
        public bool $ask_before_next_session,
        public bool $ask_for_reflection,
        public ?string $ambient_sound,
    ) {}

    public static function fromModel(FocusSetting $setting): self
    {
        return new self(
            $setting->focus_minutes,
            $setting->short_break_minutes,
            $setting->long_break_minutes,
            $setting->sessions_before_long_break,
            $setting->ask_before_next_session,
            $setting->ask_for_reflection,
            $setting->ambient_sound,
        );
    }
}
