<?php

namespace App\Jobs\Profile;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PurgeAccountFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    public int $timeout = 60;

    /**
     * @param  array<string, list<string>>  $filesByDisk
     * @param  array<string, list<string>>  $directoriesByDisk
     */
    public function __construct(
        public readonly array $filesByDisk,
        public readonly array $directoriesByDisk = [],
    ) {}

    public function handle(): void
    {
        foreach ($this->filesByDisk as $disk => $paths) {
            foreach (array_unique($paths) as $path) {
                if (! Storage::disk($disk)->delete($path)) {
                    throw new RuntimeException('Unable to remove stored account files.');
                }
            }
        }

        foreach ($this->directoriesByDisk as $disk => $paths) {
            foreach (array_unique($paths) as $path) {
                if (! Storage::disk($disk)->deleteDirectory($path)) {
                    throw new RuntimeException('Unable to remove stored account directories.');
                }
            }
        }
    }
}
