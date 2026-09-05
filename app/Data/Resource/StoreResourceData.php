<?php

namespace App\Data\Resource;

use Spatie\LaravelData\Data;

class StoreResourceData extends Data
{
    public function __construct(
        public string $title,
        public ?string $icon = null,
        public ?string $background = null,
        public ?array $content = null,
        public array $links = [],
        public array $files = [],
        public array $tag_uuids = [],
        public array $tag_names = [],
        public ?string $project_uuid = null,
        public ?string $area_uuid = null,
    ) {}
}
