<?php

namespace App\Http\Controllers\Area;

use App\Data\Area\LinkProjectData;
use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Area\LinkProjectRequest;
use App\Models\Area;
use App\Models\Project;
use Illuminate\Http\Request;

class AreaProjectController extends Controller
{
    use InteractsWithOwnedAreas;

    public function index(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->success($area->projects()->latest()->paginate(15));
    }

    public function store(LinkProjectRequest $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $data = LinkProjectData::from($request->validated());
        $project = $request->user()->projects()
            ->where('uuid', $data->project_uuid)
            ->firstOrFail();
        $project->area()->associate($area);
        $project->save();

        return $this->success($project->fresh()->load('area'), 'Successfully linked project.');
    }

    public function destroy(Request $request, Area $area, Project $project)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $project = $area->projects()->whereKey($project->getKey())->firstOrFail();
        $project->area()->dissociate();
        $project->save();

        return $this->success(null, 'Successfully detached project.');
    }
}
