<?php

namespace App\Services\Area;

use App\Data\Area\AreaData;
use App\Enum\CoreFeature;
use App\Models\Area;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiReadCacheService;
use App\Services\Plan\PlanQuotaService;
use App\Services\Profile\FileCleanupService;
use App\Support\ImageMetadataStripper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AreaService
{
    public function __construct(
        private readonly PlanQuotaService $quota,
        private readonly FileCleanupService $fileCleanup,
        private readonly ImageMetadataStripper $metadata,
    ) {}

    public function create(User $user, AreaData $data, ?UploadedFile $image = null): Area
    {
        return $this->quota->transaction($user, function (User $user) use ($data, $image): Area {
            $this->quota->assertCanIncrease($user, CoreFeature::AREAS);
            $attributes = $data->toArray();

            if ($image) {
                $this->metadata->strip($image->getRealPath());
                $filename = $image->hashName();
                $path = "areas/backgrounds/{$filename}";
                DB::afterRollBack(function () use ($path): void {
                    try {
                        $this->fileCleanup->deleteOrQueue(['public' => [$path]]);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
                if ($image->storeAs('areas/backgrounds', $filename, 'public') === false) {
                    throw new RuntimeException('Unable to store area background image.');
                }
                $attributes['background_image'] = $path;
            }

            return $user->areas()->create($attributes);
        });
    }

    public function archive(Area $area): int
    {
        return DB::transaction(function () use ($area): int {
            $area = Area::query()
                ->whereKey($area->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $projects = $area->projects()->whereNull('archived_at');
            $movedIds = $projects->pluck('id')->all();
            $projects->update(['area_id' => null]);

            // Remember which projects were moved to Inbox so unarchiving can put them back.
            $area->forceFill([
                'archived_at' => $area->archived_at ?? now(),
                'archived_project_ids' => array_values(array_unique([...($area->archived_project_ids ?? []), ...$movedIds])),
            ])->save();

            app(ApiReadCacheService::class)->invalidateScope('user:'.$area->user()->value('uuid'));

            return count($movedIds);
        });
    }

    /** Unarchives the Area and returns how many Inbox projects were moved back into it. */
    public function unarchive(Area $area): int
    {
        return DB::transaction(function () use ($area): int {
            $area = Area::query()
                ->whereKey($area->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Projects the user has since moved to another Area stay where they are.
            $relinked = Project::query()
                ->where('user_id', $area->user_id)
                ->whereIn('id', $area->archived_project_ids ?? [])
                ->whereNull('area_id')
                ->update(['area_id' => $area->getKey()]);

            $area->forceFill(['archived_at' => null, 'archived_project_ids' => null])->save();

            app(ApiReadCacheService::class)->invalidateScope('user:'.$area->user()->value('uuid'));

            return $relinked;
        });
    }
}
