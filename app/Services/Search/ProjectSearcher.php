<?php

namespace App\Services\Search;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProjectSearcher extends AbstractSearcher
{
    public function type(): string
    {
        return 'project';
    }

    protected function label(): string
    {
        return 'Projects';
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
        return Project::query()
            ->where('user_id', $user->id)
            ->with('area:id,name,archived_at')
            ->when(! $includeArchived, fn (Builder $query): Builder => $query
                ->whereNull('archived_at')
                ->whereDoesntHave('area', fn (Builder $areas): Builder => $areas->whereNotNull('archived_at')));
    }

    protected function subtitle(Model $model, string $query): ?string
    {
        return $model->area ? SearchText::context('Area · '.$model->area->name) : 'Project';
    }

    protected function archived(Model $model): bool
    {
        return $model->archived_at !== null || $model->area?->archived_at !== null;
    }
}
