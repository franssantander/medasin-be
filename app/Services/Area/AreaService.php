<?php

namespace App\Services\Area;

use App\Models\Area;
use Illuminate\Support\Facades\DB;

class AreaService
{
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

            return $area->projects()
                ->whereNull('archived_at')
                ->update(['area_id' => null]);
        });
    }
}
