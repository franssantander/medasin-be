<?php

namespace App\Support;

use App\Enum\CoreFeature;
use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanLimitExceededException extends HttpException implements ShouldntReport
{
    public function __construct(
        public readonly CoreFeature $feature,
        public readonly int $usage,
        public readonly int $limit,
    ) {
        parent::__construct(403, "The {$feature->value} limit for your current plan has been reached.");
    }
}
