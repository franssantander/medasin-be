<?php

namespace App\Http\Controllers\Area;

use App\Data\Area\AreaData;
use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Area\StoreAreaRequest;
use App\Http\Requests\Area\UpdateAreaRequest;
use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AreaController extends Controller
{
    use InteractsWithOwnedAreas;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])],
        ]);
        $status = $validated['status'] ?? 'active';
        $query = $request->user()->areas();

        if ($status === 'active') {
            $query->whereNull('archived_at');
        } elseif ($status === 'archived') {
            $query->whereNotNull('archived_at');
        }

        $data = $query->latest()->get();

        return $this->success($data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAreaRequest $request)
    {
        $attributes = AreaData::from(Arr::except($request->validated(), ['background_image']))->toArray();

        if ($request->hasFile('background_image')) {
            $attributes['background_image'] = $request->file('background_image')->store('areas/backgrounds', 'public');
        }

        $data = $request->user()
            ->areas()
            ->create($attributes);

        return $this->success($data, 'Successfully created area.', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Area $area)
    {
        $data = $this->ownedArea($request->user(), $area)->load(['projects', 'resources']);

        return $this->success($data);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAreaRequest $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $attributes = AreaData::from(Arr::except($request->validated(), ['background_image']))->toArray();

        if ($request->hasFile('background_image')) {
            $previousImage = $area->background_image;
            $attributes['background_image'] = $request->file('background_image')->store('areas/backgrounds', 'public');

            if ($previousImage) {
                Storage::disk('public')->delete($previousImage);
            }
        }

        $area->update($attributes);

        return $this->success($area->fresh(), 'Successfully updated area.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        if ($area->background_image) {
            Storage::disk('public')->delete($area->background_image);
        }
        $area->delete();

        return $this->success(null, 'Successfully deleted area.');
    }

    public function archive(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        if ($area->archived_at === null) {
            $area->forceFill(['archived_at' => now()])->save();
        }

        return $this->success($area->fresh(), 'Successfully archived area.');
    }

    public function restore(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        if ($area->archived_at !== null) {
            $area->forceFill(['archived_at' => null])->save();
        }

        return $this->success($area->fresh(), 'Successfully restored area.');
    }
}
