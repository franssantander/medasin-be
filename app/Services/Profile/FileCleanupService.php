<?php

namespace App\Services\Profile;

use App\Jobs\Profile\PurgeAccountFiles;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

class FileCleanupService
{
    /**
     * @param  array<string, list<string>>  $filesByDisk
     * @param  array<string, list<string>>  $directoriesByDisk
     */
    public function enqueue(array $filesByDisk, array $directoriesByDisk = []): void
    {
        $queue = Queue::connection('database');

        if (! $queue instanceof DatabaseQueue || $queue->getDatabase() !== DB::connection()) {
            throw new RuntimeException('File cleanup must use the account database connection.');
        }

        $queue->push((new PurgeAccountFiles($filesByDisk, $directoriesByDisk))->beforeCommit());
    }

    /** @param array<string, list<string>> $filesByDisk */
    public function deleteOrQueue(array $filesByDisk): void
    {
        try {
            (new PurgeAccountFiles($filesByDisk))->handle();
        } catch (Throwable) {
            $this->enqueue($filesByDisk);
        }
    }
}
