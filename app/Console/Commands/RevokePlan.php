<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Plan\PlanAssignmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

#[Signature('plans:revoke {user_uuid}')]
#[Description('Revoke current plan assignments and ret
urn an account to Free')]
class RevokePlan extends Command
{
    public function handle(PlanAssignmentService $assignments): int
    {
        try {
            $user = User::query()->where('uuid', $this->argument('user_uuid'))->firstOrFail();
        } catch (ModelNotFoundException) {
            $this->error('The account could not be found.');

            return self::FAILURE;
        }

        $changed = $assignments->revoke($user);
        $this->info("Revoked {$changed} plan assignment(s). Effective plan: Free.");

        return self::SUCCESS;
    }
}
