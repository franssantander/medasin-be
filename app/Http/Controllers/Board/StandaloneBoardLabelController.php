<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Board\Concerns\InteractsWithStandaloneBoards;
use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardLabelRequest;
use App\Http\Requests\Board\UpdateBoardLabelRequest;
use App\Http\Resources\Board\BoardLabelResource;
use App\Models\Board;
use App\Models\BoardLabel;
use App\Services\Trash\TrashService;
use Illuminate\Http\Request;

class StandaloneBoardLabelController extends Controller
{
    use InteractsWithStandaloneBoards;

    public function __construct(private readonly TrashService $trash) {}

    public function store(StoreBoardLabelRequest $request, Board $board)
    {
        $board = $this->standaloneBoard($request->user(), $board);
        $label = $board->labels()->create($request->validated());

        return $this->success(BoardLabelResource::make($label)->resolve($request), 'Successfully created board label.', 201);
    }

    public function update(UpdateBoardLabelRequest $request, Board $board, BoardLabel $label)
    {
        $board = $this->standaloneBoard($request->user(), $board);
        $label = $this->boardLabel($board, $label);
        $label->update($request->validated());

        return $this->success(BoardLabelResource::make($label->fresh())->resolve($request), 'Successfully updated board label.');
    }

    public function destroy(Request $request, Board $board, BoardLabel $label)
    {
        $board = $this->standaloneBoard($request->user(), $board);
        $label = $this->boardLabel($board, $label);
        $this->trash->delete($request->user(), $label, 'board_label', $label->name, $board->name);

        return $this->success(null, 'Label moved to Trash. It will be permanently deleted after 30 days.');
    }
}
