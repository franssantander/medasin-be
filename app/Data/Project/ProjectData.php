<?php

namespace App\Data\Project;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class ProjectData extends Data
{
    public function __construct(
        public string|Optional $name,
        public string|null|Optional $description,
        public string|null|Optional $icon,
        public string|null|Optional $background,
        public string|Optional $status,
        public string|null|Optional $start_date,
        public string|null|Optional $due_date,
        public string|null|Optional $area_uuid,
        public string|null|Optional $area_name,
    ) {}
}
