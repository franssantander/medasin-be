<?php

namespace App\Data\Area;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class AreaData extends Data
{
    public function __construct(
        public string|Optional $name,
        public string|null|Optional $icon,
        public string|null|Optional $background,
        public string|null|Optional $description,
    ) {}
}
