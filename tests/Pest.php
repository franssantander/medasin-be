<?php

use Tests\Feature\Auth\AuthTestCase;
use Tests\TestCase;

pest()->extend(AuthTestCase::class)->in(
    'Feature/Auth/AuthenticationWorkflowTest.php',
    'Feature/Auth/GoogleAuthenticationTest.php',
);

pest()->extend(TestCase::class)->in(
    'Feature/Area',
    'Feature/Auth/FeatureAccessTest.php',
    'Feature/Board',
    'Feature/Cache',
    'Feature/Calendar',
    'Feature/Database',
    'Feature/Focus',
    'Feature/Habit',
    'Feature/Home',
    'Feature/Journal',
    'Feature/Letter',
    'Feature/Note',
    'Feature/Notification',
    'Feature/Plan',
    'Feature/Profile',
    'Feature/Project',
    'Feature/RateLimiting',
    'Feature/Resource',
    'Feature/Search',
    'Feature/Settings',
    'Feature/Support',
    'Feature/Trash',
);
