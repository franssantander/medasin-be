<?php

namespace App\Http\Controllers\Board;

use App\Data\Board\BoardDetailData;
use App\Data\Board\BoardSummaryData;
use App\Http\Controllers\Board\Concerns\InteractsWithStandaloneBoards;
use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardRequest;
use App\Http\Requests\Board\UpdateBoardRequest;
use App\Models\Board;
use App\Services\Board\BoardService;
use App\Services\Trash\TrashService;
use Illuminate\Http\Request;

class StandaloneBoardController extends Controller
{
    use InteractsWithStandaloneBoards;

    public function __construct(private readonly BoardService $boards, private readonly TrashService $trash) {}

    public function index(Request $request)
    {
        $this->boards->ensureStandaloneBoard($request->user());
        $boards = $request->user()->boards()->whereNull('context_type')->whereNull('context_id')
            ->withCount('tasks')->with(['stages' => fn ($query) => $query->withCount('tasks')])
            ->orderBy('position')->get();

        return $this->success($boards->map(fn (Board $board): array => BoardSummaryData::fromModel($board)->toArray())->all());
    }

    public function store(StoreBoardRequest $request)
    {
        $board = $this->boards->createStandalone($request->user(), $request->validated('name'));

        return $this->success(BoardDetailData::fromModel($board)->toArray(), 'Successfully created board.', 201);
    }

    public function show(Request $request, Board $board)
    {
        return $this->success(BoardDetailData::fromModel($this->loadBoard($this->standaloneBoard($request->user(), $board)))->toArray());
    }

    public function update(UpdateBoardRequest $request, Board $board)
    {
        $board = $this->standaloneBoard($request->user(), $board);
        $board->update($request->validated());

        return $this->success(BoardDetailData::fromModel($this->loadBoard($board))->toArray(), 'Successfully updated board.');
    }

    public function destroy(Request $request, Board $board)
    {
        $board = $this->standaloneBoard($request->user(), $board);
        $this->trash->deleteStandaloneBoard($request->user(), $board);

        return $this->success(null, 'Board and its tasks moved to Trash. They will be permanently deleted after 30 days.');
    }

    private function loadBoard(Board $board): Board
    {
        return $board->fresh()->loadCount('tasks')->load([
            'labels',
            'stages' => fn ($query) => $query->withCount('tasks')->with([
                'tasks' => fn ($tasks) => $tasks->with(['stage', 'labels', 'resources.areas', 'notes.area'])->orderBy('position'),
            ]),
        ]);
    }
}
