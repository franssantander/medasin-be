<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Project\Concerns\InteractsWithOwnedProjects;
use App\Http\Requests\Board\MoveBoardTaskRequest;
use App\Http\Requests\Board\StoreBoardTaskRequest;
use App\Http\Requests\Board\UpdateBoardTaskRequest;
use App\Http\Resources\Board\BoardTaskResource;
use App\Models\Board;
use App\Models\BoardTask;
use App\Models\Project;
use App\Services\Board\BoardTaskService;
use App\Services\Trash\TrashService;
use Illuminate\Http\Request;

class ProjectBoardTaskController extends Controller
{
    use InteractsWithOwnedProjects;

    public function __construct(
        private readonly BoardTaskService $taskService,
        private readonly TrashService $trashService,
    ) {}

    public function index(Request $request, Project $project, Board $board)
    {
        $project = $this->ownedProject($request->user(), $project);
        $board = $this->ownedBoard($project, $board);
        $tasks = $board->tasks()
            ->with(['stage', 'labels', 'resources.areas', 'notes.area'])
            ->join('board_stages', 'board_stages.id', '=', 'board_tasks.board_stage_id')
            ->orderBy('board_stages.position')
            ->orderBy('board_tasks.position')
            ->select('board_tasks.*')
            ->get();

        return $this->success(BoardTaskResource::collection($tasks)->resolve($request));
    }

    public function store(StoreBoardTaskRequest $request, Project $project, Board $board)
    {
        $project = $this->ownedProject($request->user(), $project);
        $this->ensureProjectIsMutable($project);
        $board = $this->ownedBoard($project, $board);
        $task = $this->taskService->create($request->user(), $board, $request->validated());

        return $this->success(BoardTaskResource::make($task)->resolve($request), 'Successfully created board task.', 201);
    }

    public function show(Request $request, Project $project, Board $board, BoardTask $task)
    {
        $project = $this->ownedProject($request->user(), $project);
        $board = $this->ownedBoard($project, $board);
        $task = $this->boardTask($board, $task)->load([
            'stage',
            'labels',
            'resources.areas',
            'notes.area',
        ]);

        return $this->success(BoardTaskResource::make($task)->resolve($request));
    }

    public function update(UpdateBoardTaskRequest $request, Project $project, Board $board, BoardTask $task)
    {
        $project = $this->ownedProject($request->user(), $project);
        $this->ensureProjectIsMutable($project);
        $board = $this->ownedBoard($project, $board);
        $task = $this->boardTask($board, $task);
        $task = $this->taskService->update($request->user(), $board, $task, $request->validated());

        return $this->success(BoardTaskResource::make($task)->resolve($request), 'Successfully updated board task.');
    }

    public function destroy(Request $request, Project $project, Board $board, BoardTask $task)
    {
        $project = $this->ownedProject($request->user(), $project);
        $this->ensureProjectIsMutable($project);
        $board = $this->ownedBoard($project, $board);
        $task = $this->boardTask($board, $task);
        $this->trashService->delete($request->user(), $task, 'task', $task->title, "{$project->name} · {$board->name}");

        return $this->success(null, 'Task moved to Trash. It will be permanently deleted after 30 days.');
    }

    public function move(MoveBoardTaskRequest $request, Project $project, Board $board, BoardTask $task)
    {
        $project = $this->ownedProject($request->user(), $project);
        $this->ensureProjectIsMutable($project);
        $board = $this->ownedBoard($project, $board);
        $task = $this->boardTask($board, $task);
        $data = $request->validated();
        $task = $this->taskService->move($board, $task, $data['stage'], $data['position']);

        return $this->success(BoardTaskResource::make($task)->resolve($request), 'Successfully moved board task.');
    }
}
