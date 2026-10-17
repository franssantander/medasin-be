<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\User;
use App\Support\ImageMetadataStripper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes EXIF/GPS and other metadata from profile images and area backgrounds already on the public disk.
 * New uploads are stripped as they arrive; this cleans up files stored before that. Safe to run more than once.
 */
#[Signature('media:strip-metadata {--dry-run : Report how many public images still carry metadata without changing them}')]
#[Description('Strip location and camera metadata from public profile images and area backgrounds')]
class StripImageMetadata extends Command
{
    public function handle(ImageMetadataStripper $stripper): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('public');
        $paths = User::query()->whereNotNull('profile_image_path')->pluck('profile_image_path')
            ->merge(Area::withTrashed()->whereNotNull('background_image')->pluck('background_image'))
            ->filter()->unique()->values();
        $changed = 0;

        foreach ($paths as $path) {
            if (! $disk->exists($path)) {
                continue;
            }
            $target = $disk->path($path);
            if ($dryRun) {
                $copy = tempnam(sys_get_temp_dir(), 'strip');
                copy($target, $copy);
                $target = $copy;
            }
            if ($stripper->strip($target)) {
                $changed++;
            }
            if ($dryRun) {
                @unlink($target);
            }
        }

        $prefix = $dryRun ? 'Would strip' : 'Stripped';
        $this->info("{$prefix} metadata from {$changed} of {$paths->count()} public image(s).");

        return self::SUCCESS;
    }
}
