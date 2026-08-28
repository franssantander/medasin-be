<?php

namespace App\Http\Controllers\Area;

use App\Enum\GoalStatus;
use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Area\StoreGoalRequest;
use App\Http\Requests\Area\UpdateGoalRequest;
use App\Models\Area;
use App\Models\Goal;
use Illuminate\Http\Request;

class AreaGoalController extends Controller
{
    use InteractsWithOwnedAreas;

    public function index(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->success($area->goals()->latest()->paginate(15));
    }

    public function store(StoreGoalRequest $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $goal = $area->goals()->create($this->goalData($request->validated()));

        return $this->success($goal, 'Successfully created goal.', 201);
    }

    public function show(Request $request, Area $area, Goal $goal)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->success($area->goals()->whereKey($goal->getKey())->firstOrFail());
    }

    public function update(UpdateGoalRequest $request, Area $area, Goal $goal)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $goal = $area->goals()->whereKey($goal->getKey())->firstOrFail();
        $goal->update($this->goalData($request->validated(), $goal));

        return $this->success($goal->fresh(), 'Successfully updated goal.');
    }

    public function destroy(Request $request, Area $area, Goal $goal)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $area->goals()->whereKey($goal->getKey())->firstOrFail()->delete();

        return $this->success(null, 'Successfully deleted goal.');
    }

    private function goalData(array $data, ?Goal $goal = null): array
    {
        $status = $data['status'] ?? $goal?->status?->value ?? GoalStatus::PENDING->value;

        if ($status === GoalStatus::COMPLETED->value && $goal?->completed_at === null) {
            $data['completed_at'] = now();
        } elseif ($status !== GoalStatus::COMPLETED->value) {
            $data['completed_at'] = null;
        }

        return $data;
    }
}
