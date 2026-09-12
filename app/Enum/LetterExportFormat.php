<?php

namespace App\Enum;

enum LetterExportFormat: string
{
    case PORTRAIT = 'portrait';
    case SQUARE = 'square';
    case STORY = 'story';
    case LANDSCAPE = 'landscape';
}
