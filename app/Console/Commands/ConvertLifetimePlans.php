<?php

namespace App\Console\Commands;

use App\Services\Plan\PlanAssignmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plans:convert-lifetime')]
#[Description('Convert current lifetime Clarity grants to one month of recurring access once per account')]
class ConvertLifetimePlans extends Command
{
    public function handle(PlanAssignmentService $assignments): int
    {
        $converted = $assignments->convertLifetime();
        $this->info("Converted {$converted} lifetime plan assignment(s).");

        return self::SUCCESS;
    }
}
