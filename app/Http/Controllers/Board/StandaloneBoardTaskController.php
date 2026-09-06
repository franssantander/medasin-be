<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Board\Concerns\InteractsWithStandaloneBoards;
use App\Http\Controllers\Controller;
use App\Http\Requests\Board\MoveBoardTaskRequest;
use App\Http\Requests\Board\StoreBoardTaskRequest;
use App\Http\Requests\Board\UpdateBoardTaskRequest;
use App\Http\Resources\Board\BoardTaskResource;
use App\Models\Board;
use App\Models\BoardTask;
use App\Services\Board\BoardTaskService;
use App\Services\Trash\TrashService;
use Illuminate\Http\Request;

class StandaloneBoardTaskController extends Controller
{
    use InteractsWithStandaloneBoards;

    public function __construct(private readonly BoardTaskService $tasks, private readonly TrashService $trash) {}

    public function store(StoreBoardTaskRequest $request, Board $board)
    {
        $board = $this->standaloneBoard($request->user(), $board);
        $task = $this->tasks->create($request->user(), $board, $request->validated());

        return $this->success(BoardTaskResource::make($task)->resolve($request), 'Successfully created board task.', 201);
    }

    public function update(UpdateBoardTaskRequest $request, Board $board, BoardTask $task)
    {
        $board = $this->standaloneBoard($request->user(), $board);
        $task = $this->tasks->update($request->user(), $board, $this->boardTask($board, $task), $request->validated());

        return $this->success(BoardTaskResource::make($task)->resolve($request), 'Successfully updated board task.');
    }

    public function destroy(Request $request, Board $board, BoardTask $task)
    {
        $board = $this->standaloneBoard($request->user(), $board);
        $task = $this->boardTask($board, $task);
        $this->trash->delete($request->user(), $task, 'task', $task->title, $board->name);

        return $this->success(null, 'Task moved to Trash. It will be permanently deleted after 30 days.');
    }

    public function move(MoveBoardTaskRequest $request, Board $board, BoardTask $task)
    {
        $board = $this->standaloneBoard($request->user(), $board);
        $task = $this->boardTask($board, $task);
        $data = $request->validated();
        $task = $this->tasks->move($board, $task, $data['stage'], $data['position']);

        return $this->success(BoardTaskResource::make($task)->resolve($request), 'Successfully moved board task.');
    }
}
