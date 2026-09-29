<?php

namespace App\Services\Search;

use App\Models\User;

class SearchService
{
    /** @var array<int, class-string<SearcherInterface>> */
    private const SEARCHERS = [
        ProjectSearcher::class,
        AreaSearcher::class,
        ResourceSearcher::class,
        NoteSearcher::class,
        JournalSearcher::class,
        LetterSearcher::class,
        PlanSearcher::class,
        HabitSearcher::class,
        GoalSearcher::class,
    ];

    public function search(User $user, string $query, ?string $type, int $limit, bool $includeArchived): array
    {
        $groups = [];

        foreach (self::SEARCHERS as $searcherClass) {
            /** @var SearcherInterface $searcher */
            $searcher = app($searcherClass);
            if ($type !== null && $searcher->type() !== $type) {
                continue;
            }

            $group = $searcher->search($user, $query, $limit, $includeArchived);
            if ($group['total'] > 0) {
                $groups[] = $group;
            }
        }

        return ['query' => $query, 'groups' => $groups];
    }
}
