<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Profile\ProfileService;
use Illuminate\Console\Command;

class PurgeScheduledAccountDeletions extends Command
{
    protected $signature = 'accounts:purge-deleted';

    protected $description = 'Permanently delete accounts whose deletion grace period has ended';

    public function handle(ProfileService $profiles): int
    {
        $count = 0;
        User::query()->where('deletion_scheduled_at', '<=', now())->eachById(function (User $user) use ($profiles, &$count): void {
            if ($profiles->purgeScheduledDeletion($user)) {
                $count++;
            }
        });
        $this->info("Deleted {$count} account(s).");

        return self::SUCCESS;
    }
}
