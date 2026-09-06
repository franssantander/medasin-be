<?php

namespace App\Enum;

enum FocusSessionType: string
{
    case FOCUS = 'focus';
    case SHORT_BREAK = 'short_break';
    case LONG_BREAK = 'long_break';
}
