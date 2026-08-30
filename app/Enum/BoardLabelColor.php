<?php

namespace App\Enum;

enum BoardLabelColor: string
{
    case SLATE = 'slate';
    case RED = 'red';
    case ORANGE = 'orange';
    case AMBER = 'amber';
    case GREEN = 'green';
    case BLUE = 'blue';
    case VIOLET = 'violet';
    case PINK = 'pink';

    public function hex(): string
    {
        return match ($this) {
            self::SLATE => '#64748B',
            self::RED => '#EF4444',
            self::ORANGE => '#F97316',
            self::AMBER => '#F59E0B',
            self::GREEN => '#22C55E',
            self::BLUE => '#3B82F6',
            self::VIOLET => '#8B5CF6',
            self::PINK => '#EC4899',
        };
    }
}
