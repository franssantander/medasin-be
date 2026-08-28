<?php

namespace App\Http\Controllers\Area\Concerns;

use App\Models\Area;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

trait InteractsWithOwnedAreas
{
    protected function ownedArea(User $user, Area $area): Area
    {
        return $user->areas()->whereKey($area->getKey())->firstOrFail();
    }

    protected function ensureAreaIsMutable(Area $area): void
    {
        if ($area->archived_at !== null) {
            throw new ConflictHttpException('Archived areas are read-only. Restore the area before making changes.');
        }
    }
}
