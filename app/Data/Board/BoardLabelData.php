<?php

namespace App\Data\Board;

use App\Models\BoardLabel;
use Spatie\LaravelData\Data;

class BoardLabelData extends Data
{
    public function __construct(
        public string $uuid,
        public string $name,
        public string $color,
        public string $hex,
    ) {}

    public static function fromModel(BoardLabel $label): self
    {
        return new self($label->uuid, $label->name, $label->color->value, $label->color->hex());
    }
}
