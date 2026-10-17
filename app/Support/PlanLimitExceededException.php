<?php

namespace App\Support;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanLimitExceededException extends HttpException implements ShouldntReport
{
    /** @param  string  $feature  A Core feature value or `storage` (usage and limit in bytes). */
    public function __construct(
        public readonly string $feature,
        public readonly int $usage,
        public readonly int $limit,
        ?string $message = null,
    ) {
        parent::__construct(403, $message ?? "The {$feature} limit for your current plan has been reached.");
    }

    public static function forStorage(int $usage, int $limit): self
    {
        return new self('storage', $usage, $limit, "Your plan's storage is full. Permanently delete files from Trash or upgrade your plan to upload more.");
    }
}
