<?php

namespace App\Services\Search;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class JournalSearcher extends AbstractSearcher
{
    public function type(): string
    {
        return 'journal';
    }

    protected function label(): string
    {
        return 'Journal';
    }

    protected function titleColumn(): string
    {
        return 'title';
    }

    protected function fields(): array
    {
        return ['title', 'content_text'];
    }

    protected function query(User $user, bool $includeArchived): Builder
    {
        return JournalEntry::query()->where('user_id', $user->id);
    }

    protected function subtitle(Model $model, string $query): ?string
    {
        return $model->updated_at->format('M j, Y');
    }

    protected function snippet(Model $model, string $query): ?string
    {
        return SearchText::snippet($model->content_text, $query);
    }
}
