<?php

namespace App\Services\Search;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSearcher implements SearcherInterface
{
    abstract public function type(): string;

    abstract protected function label(): string;

    abstract protected function titleColumn(): string;

    /** @return array<int, string> */
    abstract protected function fields(): array;

    abstract protected function query(User $user, bool $includeArchived): Builder;

    abstract protected function subtitle(Model $model, string $query): ?string;

    protected function archived(Model $model): bool
    {
        return false;
    }

    protected function snippet(Model $model, string $query): ?string
    {
        return null;
    }

    protected function extras(Model $model): array
    {
        return [];
    }

    public function search(User $user, string $query, int $limit, bool $includeArchived): array
    {
        $pattern = SearchText::pattern($query);
        $builder = $this->query($user, $includeArchived);
        $this->match($builder, $pattern);
        $total = (clone $builder)->count();
        $items = $total === 0 ? [] : $builder
            ->orderByRaw("CASE WHEN LOWER({$this->titleColumn()}) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$pattern])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Model $model): array => $this->item($model, $query))
            ->all();

        return [
            'type' => $this->type(),
            'label' => $this->label(),
            'total' => $total,
            'items' => $items,
        ];
    }

    protected function match(Builder $builder, string $pattern): void
    {
        $builder->where(function (Builder $matches) use ($pattern): void {
            foreach ($this->fields() as $field) {
                $matches->orWhereRaw("LOWER({$field}) LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    protected function matchField(Model $model, string $query): string
    {
        foreach ($this->fields() as $field) {
            if (SearchText::contains($model->{$field}, $query)) {
                return $field;
            }
        }

        return $this->titleColumn();
    }

    private function item(Model $model, string $query): array
    {
        return [
            'id' => $model->uuid,
            'type' => $this->type(),
            'title' => $model->{$this->titleColumn()},
            'subtitle' => $this->subtitle($model, $query),
            'snippet' => $this->snippet($model, $query),
            'match_field' => $this->matchField($model, $query),
            'archived' => $this->archived($model),
            'updated_at' => $model->updated_at->toISOString(),
            ...$this->extras($model),
        ];
    }
}
