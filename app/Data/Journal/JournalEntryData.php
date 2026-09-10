<?php

namespace App\Data\Journal;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class JournalEntryData extends Data
{
    /**
     * @param  array<int, string>|Optional  $resource_uuids
     */
    public function __construct(
        public string|Optional $title,
        public string|Optional $content,
        public array|Optional $resource_uuids,
    ) {}
}
