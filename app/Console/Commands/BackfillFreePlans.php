<?php

namespace App\Console\Commands;

use App\Services\Plan\PlanAssignmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

#[Signature('plans:backfill-free')]
#[Description('Assign Free to existing accounts without changing assignment history')]
class BackfillFreePlans extends Command
{
    public function handle(PlanAssignmentService $assignments): int
    {
        try {
            $created = $assignments->backfillFree();
        } catch (ModelNotFoundException) {
            $this->error('Seed an active Free product plan before running the backfill.');

            return self::FAILURE;
        }

        $this->info("Created {$created} Free plan assignment(s).");

        return self::SUCCESS;
    }
}
