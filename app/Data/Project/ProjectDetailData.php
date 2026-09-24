<?php

namespace App\Data\Project;

use App\Data\Board\BoardSummaryData;
use App\Models\Project;
use Spatie\LaravelData\Optional;

class ProjectDetailData extends ProjectListCardData
{
    public static function fromModel(Project $project): static
    {
        $data = parent::fromModel($project);
        $data->boards = $project->relationLoaded('boards')
            ? $project->boards->map(fn ($board): array => BoardSummaryData::fromModel($board)->toArray())->all()
            : Optional::create();

        return $data;
    }
}
