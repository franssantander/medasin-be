<?php

namespace App\Http\Controllers\Project;

use App\Data\Board\BoardDetailData;
use App\Data\Board\BoardSummaryData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Project\Concerns\InteractsWithOwnedProjects;
use App\Http\Requests\Board\StoreBoardRequest;
use App\Http\Requests\Board\UpdateBoardRequest;
use App\Models\Board;
use App\Models\Project;
use App\Services\Board\BoardService;
use App\Services\Trash\TrashService;
use Illuminate\Http\Request;

class ProjectBoardController extends Controller
{
    use InteractsWithOwnedProjects;

    public function __construct(
        private readonly BoardService $boardService,
        private readonly TrashService $trashService,
    ) {}

    public function index(Request $request, Project $project)
    {
        $project = $this->ownedProject($request->user(), $project);
        $boards = $project->boards()
            ->withCount('tasks')
            ->with(['stages' => fn ($query) => $query->withCount('tasks')])
            ->get();

        return $this->success($boards->map(fn (Board $board): array => BoardSummaryData::fromModel($board)->toArray())->all());
    }

    public function store(StoreBoardRequest $request, Project $project)
    {
        $project = $this->ownedProject($request->user(), $project);
        $this->ensureProjectIsMutable($project);
        $board = $this->boardService->createForProject($request->user(), $project, $request->validated('name'));

        return $this->success(BoardDetailData::fromModel($board)->toArray(), 'Successfully created board.', 201);
    }

    public function show(Request $request, Project $project, Board $board)
    {
        $project = $this->ownedProject($request->user(), $project);
        $board = $this->ownedBoard($project, $board);

        return $this->success(BoardDetailData::fromModel($this->loadBoard($board))->toArray());
    }

    public function update(UpdateBoardRequest $request, Project $project, Board $board)
    {
        $project = $this->ownedProject($request->user(), $project);
        $this->ensureProjectIsMutable($project);
        $board = $this->ownedBoard($project, $board);
        $board->update($request->validated());

        return $this->success(BoardDetailData::fromModel($this->loadBoard($board))->toArray(), 'Successfully updated board.');
    }

    public function destroy(Request $request, Project $project, Board $board)
    {
        $project = $this->ownedProject($request->user(), $project);
        $this->ensureProjectIsMutable($project);
        $board = $this->ownedBoard($project, $board);
        $this->trashService->deleteBoard($request->user(), $project, $board);

        return $this->success(null, 'Board and its tasks moved to Trash. They will be permanently deleted after 30 days.');
    }

    private function loadBoard(Board $board): Board
    {
        return $board->fresh()
            ->loadCount('tasks')
            ->load([
                'labels',
                'stages' => fn ($query) => $query->withCount('tasks')->with([
                    'tasks' => fn ($tasks) => $tasks->with([
                        'stage',
                        'labels',
                        'resources.areas',
                        'notes.area',
                    ])->orderBy('position'),
                ]),
            ]);
    }
}
