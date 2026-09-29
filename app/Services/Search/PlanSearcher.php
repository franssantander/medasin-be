<?php

namespace App\Services\Search;

use App\Models\CalendarPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PlanSearcher extends AbstractSearcher
{
    public function type(): string
    {
        return 'plan';
    }

    protected function label(): string
    {
        return 'Plans';
    }

    protected function titleColumn(): string
    {
        return 'title';
    }

    protected function fields(): array
    {
        return ['title', 'notes'];
    }

    protected function query(User $user, bool $includeArchived): Builder
    {
        return CalendarPlan::query()
            ->where('user_id', $user->id)
            ->with('area:id,name');
    }

    protected function subtitle(Model $model, string $query): ?string
    {
        $context = $model->event_date->format('M j, Y');
        if ($model->area) {
            $context .= ' · '.$model->area->name;
        }

        return SearchText::context($context);
    }
}
