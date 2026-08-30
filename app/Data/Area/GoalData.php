<?php

namespace App\Data\Area;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class GoalData extends Data
{
    public function __construct(
        public string|Optional $title,
        public string|Optional $icon,
        public string|null|Optional $description,
        public string|Optional $status,
        public string|null|Optional $start_date,
        public string|null|Optional $due_date,
    ) {}
}
