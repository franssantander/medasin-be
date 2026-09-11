<?php

namespace App\Data\Letter;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class LetterData extends Data
{
    public function __construct(
        public string|Optional $title,
        public string|null|Optional $subtitle,
        public string|Optional $content,
    ) {}
}
