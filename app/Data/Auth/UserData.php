<?php

namespace App\Data\Auth;

use App\Enum\Status;
use Spatie\LaravelData\Data;

class UserData extends Data
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $first_name,
        public string $last_name,
        public string $email,
        public string $username,
        public ?Status $status,
    ) {}
}
