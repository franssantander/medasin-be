<?php

namespace App\Services\Search;

use App\Models\User;

interface SearcherInterface
{
    public function type(): string;

    public function search(User $user, string $query, int $limit, bool $includeArchived): array;
}
