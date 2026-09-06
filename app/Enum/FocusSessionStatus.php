<?php

namespace App\Enum;

enum FocusSessionStatus: string
{
    case RUNNING = 'running';
    case PAUSED = 'paused';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
