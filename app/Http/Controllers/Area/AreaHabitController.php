<?php

namespace App\Http\Controllers\Area;

use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Area\StoreHabitRequest;
use App\Http\Requests\Area\UpdateHabitRequest;
use App\Models\Area;
use App\Models\Habit;
use Illuminate\Http\Request;

class AreaHabitController extends Controller
{
    use InteractsWithOwnedAreas;

    public function index(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->success($area->habits()->latest()->paginate(15));
    }

    public function store(StoreHabitRequest $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $habit = $area->habits()->create($request->validated());

        return $this->success($habit, 'Successfully created habit.', 201);
    }

    public function show(Request $request, Area $area, Habit $habit)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->success($area->habits()->whereKey($habit->getKey())->firstOrFail());
    }

    public function update(UpdateHabitRequest $request, Area $area, Habit $habit)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $habit = $area->habits()->whereKey($habit->getKey())->firstOrFail();
        $habit->update($request->validated());

        return $this->success($habit->fresh(), 'Successfully updated habit.');
    }

    public function destroy(Request $request, Area $area, Habit $habit)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $area->habits()->whereKey($habit->getKey())->firstOrFail()->delete();

        return $this->success(null, 'Successfully deleted habit.');
    }
}
