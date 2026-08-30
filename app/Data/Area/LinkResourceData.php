<?php

namespace App\Data\Area;

use Spatie\LaravelData\Data;

class LinkResourceData extends Data
{
    public function __construct(public string $resource_uuid) {}
}
