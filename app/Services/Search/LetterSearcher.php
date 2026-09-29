<?php

namespace App\Services\Search;

use App\Models\Letter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LetterSearcher extends AbstractSearcher
{
    public function type(): string
    {
        return 'letter';
    }

    protected function label(): string
    {
        return 'Letters';
    }

    protected function titleColumn(): string
    {
        return 'title';
    }

    protected function fields(): array
    {
        return ['title', 'subtitle', 'content_text'];
    }

    protected function query(User $user, bool $includeArchived): Builder
    {
        return Letter::query()->where('user_id', $user->id);
    }

    protected function subtitle(Model $model, string $query): ?string
    {
        return SearchText::context($model->subtitle) ?? 'Letter';
    }

    protected function snippet(Model $model, string $query): ?string
    {
        return SearchText::snippet($model->content_text, $query);
    }
}
