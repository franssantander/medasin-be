<?php

namespace App\Services\Search;

use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NoteSearcher extends AbstractSearcher
{
    public function type(): string
    {
        return 'note';
    }

    protected function label(): string
    {
        return 'Notes';
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
        return Note::query()
            ->where('user_id', $user->id)
            ->with('area:id,name,archived_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('area_id')
                ->orWhereHas('area', fn (Builder $areas): Builder => $areas
                    ->when(! $includeArchived, fn (Builder $active): Builder => $active->whereNull('archived_at'))));
    }

    protected function subtitle(Model $model, string $query): ?string
    {
        return $model->area ? SearchText::context('Area · '.$model->area->name) : 'Notes';
    }

    protected function snippet(Model $model, string $query): ?string
    {
        return SearchText::snippet($model->content_text, $query);
    }

    protected function archived(Model $model): bool
    {
        return $model->area?->archived_at !== null;
    }
}
