<?php

namespace App\Enum;

enum PlanAssignmentStatus: string
{
    case ACTIVE = 'active';
    case SUPERSEDED = 'superseded';
    case REVOKED = 'revoked';
}
