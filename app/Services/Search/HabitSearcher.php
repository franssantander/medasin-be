<?php

namespace App\Services\Search;

use App\Models\Habit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HabitSearcher extends AbstractSearcher
{
    public function type(): string
    {
        return 'habit';
    }

    protected function label(): string
    {
        return 'Habits';
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
        return Habit::query()->where('user_id', $user->id)->with('area:id,name');
    }

    protected function subtitle(Model $model, string $query): ?string
    {
        return $model->area ? SearchText::context('Area · '.$model->area->name) : 'Habit';
    }
}
