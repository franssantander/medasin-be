<?php

namespace App\Http\Controllers\Project\Concerns;

use App\Models\Board;
use App\Models\BoardLabel;
use App\Models\BoardTask;
use App\Models\Project;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

trait InteractsWithOwnedProjects
{
    protected function ownedProject(User $user, Project $project): Project
    {
        return $user->projects()->whereKey($project->getKey())->firstOrFail();
    }

    protected function ownedBoard(Project $project, Board $board): Board
    {
        return $project->boards()->whereKey($board->getKey())->firstOrFail();
    }

    protected function boardTask(Board $board, BoardTask $task): BoardTask
    {
        return $board->tasks()->whereKey($task->getKey())->firstOrFail();
    }

    protected function boardLabel(Board $board, BoardLabel $label): BoardLabel
    {
        return $board->labels()->whereKey($label->getKey())->firstOrFail();
    }

    protected function ensureProjectIsMutable(Project $project): void
    {
        if ($project->archived_at !== null) {
            throw new ConflictHttpException('Archived projects are read-only. Restore the project before making changes.');
        }
    }
}
