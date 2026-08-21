<?php

namespace App\Data\Auth;

use App\Enum\Status;
use Spatie\LaravelData\Data;

class UserData extends Data
{
    public function __construct(
        public int $id,
        public int $uuid,
        public int $first_name,
        public int $last_name,
        public int $email,
        public int $username,
        public ?Status $status,
    ) {}
}