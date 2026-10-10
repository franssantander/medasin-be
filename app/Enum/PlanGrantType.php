<?php

namespace App\Enum;

enum PlanGrantType: string
{
    case FREE = 'free';
    case RECURRING = 'recurring';
    case LIFETIME = 'lifetime';
}
