<?php

namespace App\Services\Project;

use App\Data\Project\ProjectAreaData;
use App\Data\Project\ProjectData;
use App\Models\Project;
use App\Models\User;
use App\Services\Board\BoardService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function __construct(private readonly BoardService $boardService) {}

    public function create(User $user, ProjectData $data): Project
    {
        $request = $data->toArray();
        $data = DB::transaction(function () use ($request, $user) {
            if (isset($request['area_uuid'])) {
                $area = $user->areas()
                    ->whereNull('archived_at')
                    ->where('uuid', $request['area_uuid'])
                    ->firstOrFail();
            } elseif (isset($request['area_name'])) {
                $areaName = trim($request['area_name']);
                $slug = Str::slug($areaName);
                $area = $user->areas()
                    ->whereNull('archived_at')
                    ->where('slug', $slug)
                    ->first();

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

            $project = $user->projects()->make(
                Arr::except($request, ['area_uuid', 'area_name']),
            );
            if (isset($area)) {
                $project->area()->associate($area);
            }
            $project->save();
            $this->boardService->createForProject($user, $project);

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

    /**
     * @return array{project: Project, moved_to_inbox: bool}
     */
    public function restore(Project $project): array
    {
        return DB::transaction(function () use ($project): array {
            $movedToInbox = $project->area_id !== null
                && ! $project->area()->whereNull('archived_at')->exists();

            if ($movedToInbox) {
                $project->area()->dissociate();
            }

            $project->forceFill(['archived_at' => null])->save();

            return [
                'project' => $project->fresh(),
                'moved_to_inbox' => $movedToInbox,
            ];
        });
    }
}
