<?php

namespace App\Console\Commands;

use App\Models\TrashEntry;
use App\Services\Trash\TrashService;
use Illuminate\Console\Command;

class PruneTrash extends Command
{
    protected $signature = 'trash:prune';

    protected $description = 'Permanently delete expired trash entries';

    public function handle(TrashService $trashService): int
    {
        $count = 0;
        TrashEntry::query()->where('expires_at', '<=', now())->orderBy('id')->eachById(function (TrashEntry $entry) use ($trashService, &$count): void {
            $trashService->forceDelete($entry);
            $count++;
        });
        $this->info("Pruned {$count} trash entries.");

        return self::SUCCESS;
    }
}
