<?php

namespace App\Enum;

enum BoardStageKey: string
{
    case BACKLOG = 'backlog';
    case TODOS = 'todos';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';

    public function label(): string
    {
        return match ($this) {
            self::BACKLOG => 'Backlog',
            self::TODOS => 'Todos',
            self::IN_PROGRESS => 'In Progress',
            self::DONE => 'Done',
        };
    }
}
