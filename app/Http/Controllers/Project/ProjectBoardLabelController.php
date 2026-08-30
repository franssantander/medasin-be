<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Project\Concerns\InteractsWithOwnedProjects;
use App\Http\Requests\Board\StoreBoardLabelRequest;
use App\Http\Requests\Board\UpdateBoardLabelRequest;
use App\Http\Resources\Board\BoardLabelResource;
use App\Models\Board;
use App\Models\BoardLabel;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectBoardLabelController extends Controller
{
    use InteractsWithOwnedProjects;

    public function index(Request $request, Project $project, Board $board)
    {
        $project = $this->ownedProject($request->user(), $project);
        $board = $this->ownedBoard($project, $board);

        return $this->success(BoardLabelResource::collection($board->labels)->resolve($request));
    }

    public function store(StoreBoardLabelRequest $request, Project $project, Board $board)
    {
        $project = $this->ownedProject($request->user(), $project);
        $this->ensureProjectIsMutable($project);
        $board = $this->ownedBoard($project, $board);
        $label = $board->labels()->create($request->validated());

        return $this->success(BoardLabelResource::make($label)->resolve($request), 'Successfully created board label.', 201);
    }

    public function update(UpdateBoardLabelRequest $request, Project $project, Board $board, BoardLabel $label)
    {
        $project = $this->ownedProject($request->user(), $project);
        $this->ensureProjectIsMutable($project);
        $board = $this->ownedBoard($project, $board);
        $label = $this->boardLabel($board, $label);
        $label->update($request->validated());

        return $this->success(BoardLabelResource::make($label->fresh())->resolve($request), 'Successfully updated board label.');
    }

    public function destroy(Request $request, Project $project, Board $board, BoardLabel $label)
    {
        $project = $this->ownedProject($request->user(), $project);
        $this->ensureProjectIsMutable($project);
        $board = $this->ownedBoard($project, $board);
        $this->boardLabel($board, $label)->delete();

        return $this->success(null, 'Successfully deleted board label.');
    }
}
