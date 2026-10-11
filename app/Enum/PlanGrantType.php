<?php

namespace App\Enum;

enum PlanGrantType: string
{
    case FREE = 'free';
    case RECURRING = 'recurring';

    /** Historical records only; new lifetime grants are unsupported. */
    case LIFETIME = 'lifetime';
}
