<?php

namespace App\Http\Controllers\Focus;

use App\Data\Focus\FocusTaskData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Focus\StoreFocusTaskRequest;
use App\Http\Requests\Focus\UpdateFocusTaskRequest;
use App\Models\FocusTask;
use App\Services\Focus\FocusService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FocusTaskController extends Controller
{
    public function __construct(private readonly FocusService $focus) {}

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['active', 'completed', 'all'])]]);

        return $this->success($this->focus->tasks($request->user(), $data['status'] ?? 'active')->map(fn (FocusTask $task): array => FocusTaskData::fromModel($task)->toArray())->all());
    }

    public function store(StoreFocusTaskRequest $request)
    {
        $task = $this->focus->createTask($request->user(), $request->validated());

        return $this->success(FocusTaskData::fromModel($task)->toArray(), 'Focus task added.', 201);
    }

    public function update(UpdateFocusTaskRequest $request, FocusTask $focusTask)
    {
        $task = $this->focus->updateTask($request->user(), $focusTask, $request->validated());

        return $this->success(FocusTaskData::fromModel($task)->toArray(), 'Focus task updated.');
    }

    public function destroy(Request $request, FocusTask $focusTask)
    {
        $this->focus->deleteTask($request->user(), $focusTask);

        return $this->success(null, 'Focus task removed.');
    }
}
