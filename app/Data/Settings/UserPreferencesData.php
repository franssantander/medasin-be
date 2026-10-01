<?php

namespace App\Data\Settings;

use Spatie\LaravelData\Data;

class UserPreferencesData extends Data
{
    public function __construct(public string $font_family) {}
}
