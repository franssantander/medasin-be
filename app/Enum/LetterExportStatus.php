<?php

namespace App\Enum;

enum LetterExportStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';
}
