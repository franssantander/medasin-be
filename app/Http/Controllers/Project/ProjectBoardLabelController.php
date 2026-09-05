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
use App\Services\Trash\TrashService;
use Illuminate\Http\Request;

class ProjectBoardLabelController extends Controller
{
    use InteractsWithOwnedProjects;

    public function __construct(private readonly TrashService $trashService) {}

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
        $label = $this->boardLabel($board, $label);
        $this->trashService->delete($request->user(), $label, 'board_label', $label->name, "{$project->name} · {$board->name}");

        return $this->success(null, 'Label moved to Trash. It will be permanently deleted after 30 days.');
    }
}
