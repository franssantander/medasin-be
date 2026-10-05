<?php

namespace App\Http\Controllers\Area;

use App\Data\Area\LinkResourceData;
use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Area\LinkResourceRequest;
use App\Models\Area;
use App\Models\Resource;
use App\Services\ApiReadCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AreaResourceController extends Controller
{
    use InteractsWithOwnedAreas;

    public function index(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->cached($request, fn (): JsonResponse => $this->success($area->resources()->latest('resources.created_at')->paginate(15)));
    }

    public function store(LinkResourceRequest $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $data = LinkResourceData::from($request->validated());
        $resource = $request->user()->resources()
            ->where('uuid', $data->resource_uuid)
            ->firstOrFail();
        DB::transaction(function () use ($request, $area, $resource): void {
            $area->resources()->syncWithoutDetaching([$resource->getKey()]);
            app(ApiReadCacheService::class)->invalidateUser($request->user());
        });

        return $this->success($resource, 'Successfully linked resource.');
    }

    public function destroy(Request $request, Area $area, Resource $resource)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $resource = $area->resources()->whereKey($resource->getKey())->firstOrFail();
        DB::transaction(function () use ($request, $area, $resource): void {
            $area->resources()->detach($resource);
            app(ApiReadCacheService::class)->invalidateUser($request->user());
        });

        return $this->success(null, 'Successfully detached resource.');
    }
}
