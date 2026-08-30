<?php

namespace App\Data\Area;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class NoteData extends Data
{
    public function __construct(
        public string|Optional $title,
        public string|Optional $content,
        public bool|Optional $is_pinned,
        public string|null|Optional $parent_uuid,
    ) {}
}
