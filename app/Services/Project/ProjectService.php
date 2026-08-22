<?php

namespace App\Services\Project;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class ProjectService
{

    public function create($user, $request)
    {
        $data = DB::transaction(function () use ($request, $user) {
            if (isset($request['area_uuid'])) {
                $area = $user->areas()
                    ->where('uuid', $request['area_uuid'])
                    ->firstOrFail();
            } else {
                $areaName = trim($request['area_name']);
                $area = $user->areas()->firstOrCreate(
                    ['slug' => Str::slug($areaName)],
                    ['name' => $areaName],
                );
            }

            $project = $user->projects()->make(
                Arr::except($request, ['area_uuid', 'area_name']),
            );
            $project->area()->associate($area);
            $project->save();

            return $project->load('area');
        });

        return $data;
    }
}