<?php

namespace App\Data\Area;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class HabitData extends Data
{
    public function __construct(
        public string|Optional $name,
        public string|Optional $icon,
        public string|null|Optional $description,
        public string|Optional $frequency,
        public array|null|Optional $schedule,
        public bool|Optional $is_active,
    ) {}
}
