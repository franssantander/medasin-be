<?php

namespace App\Services\Search;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GoalSearcher extends AbstractSearcher
{
    public function type(): string
    {
        return 'goal';
    }

    protected function label(): string
    {
        return 'Goals';
    }

    protected function titleColumn(): string
    {
        return 'title';
    }

    protected function fields(): array
    {
        return ['title', 'description'];
    }

    protected function query(User $user, bool $includeArchived): Builder
    {
        return Goal::query()
            ->with('area:id,uuid,name,archived_at')
            ->whereHas('area', fn (Builder $areas): Builder => $areas
                ->where('user_id', $user->id)
                ->when(! $includeArchived, fn (Builder $active): Builder => $active->whereNull('archived_at')));
    }

    protected function subtitle(Model $model, string $query): ?string
    {
        return SearchText::context('Area · '.$model->area->name);
    }

    protected function archived(Model $model): bool
    {
        return $model->area->archived_at !== null;
    }

    protected function extras(Model $model): array
    {
        return ['area_uuid' => $model->area->uuid];
    }
}
