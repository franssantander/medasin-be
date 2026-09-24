<?php

namespace App\Http\Controllers\Focus;

use App\Data\Focus\FocusSessionData;
use App\Data\Focus\FocusSettingData;
use App\Data\Focus\FocusTaskData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Focus\UpdateFocusSettingsRequest;
use App\Models\FocusTask;
use App\Services\Focus\FocusService;
use Illuminate\Http\Request;

class FocusController extends Controller
{
    public function __construct(private readonly FocusService $focus) {}

    public function show(Request $request)
    {
        $data = $request->validate(['timezone' => ['sometimes', 'timezone']]);
        $dashboard = $this->focus->dashboard($request->user(), $data['timezone'] ?? 'UTC');

        return $this->success([
            'settings' => FocusSettingData::fromModel($dashboard['settings'])->toArray(),
            'tasks' => $dashboard['tasks']->map(fn (FocusTask $task): array => FocusTaskData::fromModel($task)->toArray())->all(),
            'active_session' => $dashboard['active_session'] ? FocusSessionData::fromModel($dashboard['active_session'])->toArray() : null,
            'today' => $dashboard['today'],
            'suggested_next_type' => $dashboard['suggested_next_type'],
        ]);
    }

    public function linkableTasks(Request $request)
    {
        $data = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:120']]);
        $tasks = $this->focus->linkableTasks($request->user(), $data['search'] ?? null)->map(function ($task) {
            return [
                'uuid' => $task->uuid,
                'title' => $task->title,
                'project' => $task->board->context?->name,
                'board' => $task->board->name,
                'stage' => $task->stage->name,
            ];
        })->values();

        return $this->success($tasks);
    }

    public function updateSettings(UpdateFocusSettingsRequest $request)
    {
        $settings = $this->focus->updateSettings($request->user(), $request->validated());

        return $this->success(FocusSettingData::fromModel($settings)->toArray(), 'Focus settings updated.');
    }
}
