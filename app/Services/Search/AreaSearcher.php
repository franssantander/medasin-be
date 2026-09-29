<?php

namespace App\Services\Search;

use App\Models\Area;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AreaSearcher extends AbstractSearcher
{
    public function type(): string
    {
        return 'area';
    }

    protected function label(): string
    {
        return 'Areas';
    }

    protected function titleColumn(): string
    {
        return 'name';
    }

    protected function fields(): array
    {
        return ['name', 'description'];
    }

    protected function query(User $user, bool $includeArchived): Builder
    {
        return Area::query()
            ->where('user_id', $user->id)
            ->when(! $includeArchived, fn (Builder $query): Builder => $query->whereNull('archived_at'));
    }

    protected function subtitle(Model $model, string $query): ?string
    {
        return SearchText::context($model->description) ?? 'Area';
    }

    protected function archived(Model $model): bool
    {
        return $model->archived_at !== null;
    }
}
