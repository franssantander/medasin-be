<?php

namespace App\Http\Controllers\Board\Concerns;

use App\Models\Board;
use App\Models\BoardLabel;
use App\Models\BoardTask;
use App\Models\User;

trait InteractsWithStandaloneBoards
{
    protected function standaloneBoard(User $user, Board $board): Board
    {
        return $user->boards()->whereNull('context_type')->whereNull('context_id')->whereKey($board->getKey())->firstOrFail();
    }

    protected function boardTask(Board $board, BoardTask $task): BoardTask
    {
        return $board->tasks()->whereKey($task->getKey())->firstOrFail();
    }

    protected function boardLabel(Board $board, BoardLabel $label): BoardLabel
    {
        return $board->labels()->whereKey($label->getKey())->firstOrFail();
    }
}
