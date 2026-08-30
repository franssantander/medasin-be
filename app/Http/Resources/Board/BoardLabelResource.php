<?php

namespace App\Http\Resources\Board;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoardLabelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'color' => $this->color->value,
            'hex' => $this->color->hex(),
        ];
    }
}
