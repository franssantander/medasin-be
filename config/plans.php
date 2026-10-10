<?php

return [
    'enforcement_enabled' => env('PLAN_ENFORCEMENT_ENABLED', true),

    'limits' => [
        'free' => ['projects' => 10, 'areas' => 5, 'resources' => 100],
        'focus' => ['projects' => 50, 'areas' => 20, 'resources' => 1000],
        'clarity' => ['projects' => null, 'areas' => null, 'resources' => null],
    ],

    'deprecated_limits' => [
        'journal_entries', 'notes', 'attachments_mb', 'reminders',
        'pomodoro', 'kanban_boards', 'kanban_tasks',
    ],
];
