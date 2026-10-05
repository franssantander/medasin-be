<?php

namespace App\Http\Controllers\Area;

use App\Data\Area\AreaData;
use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Area\StoreAreaRequest;
use App\Http\Requests\Area\UpdateAreaRequest;
use App\Models\Area;
use App\Models\User;
use App\Services\Area\AreaService;
use App\Services\Profile\FileCleanupService;
use App\Services\Trash\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class AreaController extends Controller
{
    use InteractsWithOwnedAreas;

    public function __construct(
        private readonly AreaService $areaService,
        private readonly TrashService $trashService,
        private readonly FileCleanupService $fileCleanup,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])],
        ]);
        $status = $validated['status'] ?? 'active';

        return $this->cached($request, function () use ($request, $status): JsonResponse {
            $query = $request->user()->areas();

            if ($status === 'active') {
                $query->whereNull('archived_at');
            } elseif ($status === 'archived') {
                $query->whereNotNull('archived_at');
            }

            $data = $query->latest()->get();

            return $this->success($data);
        });
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

        $data = DB::transaction(function () use ($request, $attributes): Area {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());

            if ($request->hasFile('background_image')) {
                $image = $request->file('background_image');
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

        return $this->success($data, 'Successfully created area.', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Area $area)
    {
        $data = $this->ownedArea($request->user(), $area);

        return $this->cached($request, fn (): JsonResponse => $this->success($data->load(['projects', 'resources'])));
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
        $attributes = AreaData::from(Arr::except($request->validated(), ['background_image']))->toArray();

        $area = DB::transaction(function () use ($request, $area, $attributes): Area {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());
            $area = $this->ownedArea($user, $area);
            $this->ensureAreaIsMutable($area);

            if ($request->hasFile('background_image')) {
                $previousImage = $area->background_image;
                $image = $request->file('background_image');
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

                if ($previousImage) {
                    DB::afterCommit(function () use ($previousImage): void {
                        try {
                            $this->fileCleanup->deleteOrQueue(['public' => [$previousImage]]);
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    });
                }
            }

            $area->update($attributes);

            return $area->fresh();
        });

        return $this->success($area, 'Successfully updated area.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $this->trashService->delete($request->user(), $area, 'area', $area->name);

        return $this->success(null, 'Area moved to Trash. It will be permanently deleted after 30 days.');
    }

    public function archive(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $movedProjectsCount = $this->areaService->archive($area);
        $message = 'Successfully archived area.';

        if ($movedProjectsCount > 0) {
            $projectLabel = $movedProjectsCount === 1 ? 'project' : 'projects';
            $message .= " {$movedProjectsCount} active {$projectLabel} moved to Inbox.";
        }

        return $this->success($area->fresh(), $message);
    }

    public function restore(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        if ($area->archived_at !== null) {
            $area->forceFill(['archived_at' => null])->saveOrFail();
        }

        return $this->success($area->fresh(), 'Successfully restored area.');
    }
}
