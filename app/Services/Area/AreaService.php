<?php

namespace App\Services\Area;

use App\Data\Area\AreaData;
use App\Enum\CoreFeature;
use App\Models\Area;
use App\Models\User;
use App\Services\ApiReadCacheService;
use App\Services\Plan\PlanQuotaService;
use App\Services\Profile\FileCleanupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AreaService
{
    public function __construct(
        private readonly PlanQuotaService $quota,
        private readonly FileCleanupService $fileCleanup,
    ) {}

    public function create(User $user, AreaData $data, ?UploadedFile $image = null): Area
    {
        return $this->quota->transaction($user, function (User $user) use ($data, $image): Area {
            $this->quota->assertCanIncrease($user, CoreFeature::AREAS);
            $attributes = $data->toArray();

            if ($image) {
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

            if ($area->archived_at === null) {
                $area->forceFill(['archived_at' => now()])->save();
            }

            $moved = $area->projects()
                ->whereNull('archived_at')
                ->update(['area_id' => null]);

            app(ApiReadCacheService::class)->invalidateScope('user:'.$area->user()->value('uuid'));

            return $moved;
        });
    }
}
