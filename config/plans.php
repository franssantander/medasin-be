<?php

/*
 * Product tiers are defined only here. `db:seed` (PlanSeeder) syncs them into
 * the `plans` catalog; never edit plan rows directly.
 */
return [
    'enforcement_enabled' => env('PLAN_ENFORCEMENT_ENABLED', true),

    'tiers' => [
        'free' => [
            'name' => 'Free',
            'description' => 'Every Medasin tool, with room to set up your system.',
            'price_minor' => 0,
            'currency' => 'PHP',
            'billing_interval' => null,
            'limits' => ['projects' => 10, 'areas' => 5, 'resources' => 100, 'storage_mb' => 1024],
        ],
        'focus' => [
            'name' => 'Focus',
            'description' => 'Every tool, with more room for projects, areas, resources, and files.',
            'price_minor' => 9900,
            'currency' => 'PHP',
            'billing_interval' => 'month',
            'limits' => ['projects' => 50, 'areas' => 20, 'resources' => 1000, 'storage_mb' => 10240],
        ],
        'clarity' => [
            'name' => 'Clarity',
            'description' => 'Every tool, with unlimited projects, areas, and resources and the most storage.',
            'price_minor' => 14900,
            'currency' => 'PHP',
            'billing_interval' => 'month',
            'limits' => ['projects' => null, 'areas' => null, 'resources' => null, 'storage_mb' => 102400],
        ],
    ],

    'deprecated_limits' => [
        'journal_entries', 'notes', 'attachments_mb', 'reminders',
        'pomodoro', 'kanban_boards', 'kanban_tasks',
    ],
];
