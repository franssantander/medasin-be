<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'users.{userId}.notifications',
    fn (User $user, string $userId): bool => (int) $user->getKey() === (int) $userId,
    ['guards' => ['api']],
);
