<?php

namespace App\Http\Controllers\Area;

use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Area\LinkResourceRequest;
use App\Models\Area;
use App\Models\Resource;
use Illuminate\Http\Request;

class AreaResourceController extends Controller
{
    use InteractsWithOwnedAreas;

    public function index(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->success($area->resources()->latest('resources.created_at')->paginate(15));
    }

    public function store(LinkResourceRequest $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $resource = $request->user()->resources()
            ->where('uuid', $request->validated('resource_uuid'))
            ->firstOrFail();
        $area->resources()->syncWithoutDetaching([$resource->getKey()]);

        return $this->success($resource, 'Successfully linked resource.');
    }

    public function destroy(Request $request, Area $area, Resource $resource)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $resource = $area->resources()->whereKey($resource->getKey())->firstOrFail();
        $area->resources()->detach($resource);

        return $this->success(null, 'Successfully detached resource.');
    }
}
