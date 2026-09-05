<?php

namespace App\Data\Resource;

use Spatie\LaravelData\Data;

class ListResourceData extends Data
{
    public function __construct(
        public int $page = 1,
        public int $per_page = 15,
        public ?string $search = null,
        public ?string $type = null,
        public ?string $tag_uuid = null,
        public string $status = 'active',
    ) {}
}
