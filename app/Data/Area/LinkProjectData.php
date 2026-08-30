<?php

namespace App\Data\Area;

use Spatie\LaravelData\Data;

class LinkProjectData extends Data
{
    public function __construct(public string $project_uuid) {}
}
