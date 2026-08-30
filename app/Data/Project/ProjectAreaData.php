<?php

namespace App\Data\Project;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class ProjectAreaData extends Data
{
    public function __construct(
        public string|null|Optional $area_uuid,
        public string|null|Optional $area_name,
    ) {}
}
