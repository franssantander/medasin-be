<?php

namespace App\Services\Project;

use App\Data\Project\ProjectAreaData;
use App\Data\Project\ProjectData;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function create(User $user, ProjectData $data): Project
    {
        $request = $data->toArray();
        $data = DB::transaction(function () use ($request, $user) {
            if (isset($request['area_uuid'])) {
                $area = $user->areas()
                    ->where('uuid', $request['area_uuid'])
                    ->firstOrFail();
            } elseif (isset($request['area_name'])) {
                $areaName = trim($request['area_name']);
                $area = $user->areas()->firstOrCreate(
                    ['slug' => Str::slug($areaName)],
                    ['name' => $areaName],
                );
            }

            $project = $user->projects()->make(
                Arr::except($request, ['area_uuid', 'area_name']),
            );
            if (isset($area)) {
                $project->area()->associate($area);
            }
            $project->save();

            return $project->load('area');
        });

        return $data;
    }

    public function updateArea(User $user, Project $project, ProjectAreaData $data): Project
    {
        $request = $data->toArray();

        return DB::transaction(function () use ($user, $project, $request) {
            if (! isset($request['area_uuid']) && ! isset($request['area_name'])) {
                $project->area()->dissociate();
                $project->save();

                return $project->fresh()->load('area');
            }

            if (isset($request['area_uuid'])) {
                $area = $user->areas()
                    ->whereNull('archived_at')
                    ->where('uuid', $request['area_uuid'])
                    ->firstOrFail();
            } else {
                $areaName = trim($request['area_name']);
                $slug = Str::slug($areaName);
                $area = $user->areas()->whereNull('archived_at')->where('slug', $slug)->first();

                if (! $area) {
                    $unavailableAreaExists = $user->areas()
                        ->withTrashed()
                        ->where('slug', $slug)
                        ->exists();

                    if ($unavailableAreaExists) {
                        throw ValidationException::withMessages([
                            'area_name' => 'An unavailable area with this name already exists.',
                        ]);
                    }

                    $area = $user->areas()->create(['name' => $areaName]);
                }
            }

            $project->area()->associate($area);
            $project->save();

            return $project->fresh()->load([
                'area' => fn ($query) => $query->withCount('goals'),
            ]);
        });
    }
}
