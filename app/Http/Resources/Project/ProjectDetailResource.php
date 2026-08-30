<?php

namespace App\Http\Resources\Project;

use App\Http\Resources\Board\BoardSummaryResource;
use Illuminate\Http\Request;

class ProjectDetailResource extends ProjectListCardResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'boards' => BoardSummaryResource::collection($this->whenLoaded('boards')),
        ];
    }
}
