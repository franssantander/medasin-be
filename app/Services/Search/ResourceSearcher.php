<?php

namespace App\Services\Search;

use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ResourceSearcher extends AbstractSearcher
{
    public function type(): string
    {
        return 'resource';
    }

    protected function label(): string
    {
        return 'Resources';
    }

    protected function titleColumn(): string
    {
        return 'title';
    }

    protected function fields(): array
    {
        return ['title', 'description', 'content_text', 'url'];
    }

    protected function query(User $user, bool $includeArchived): Builder
    {
        return Resource::query()
            ->where('user_id', $user->id)
            ->with('attachments:id,resource_id,kind,url,original_name')
            ->when(! $includeArchived, fn (Builder $query): Builder => $query->whereNull('archived_at'));
    }

    protected function match(Builder $builder, string $pattern): void
    {
        $builder->where(function (Builder $matches) use ($pattern): void {
            foreach ($this->fields() as $field) {
                $matches->orWhereRaw("LOWER({$field}) LIKE ? ESCAPE '!'", [$pattern]);
            }
            $matches->orWhereHas('attachments', fn (Builder $attachments): Builder => $attachments
                ->where(fn (Builder $fields): Builder => $fields
                    ->whereRaw("LOWER(url) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(original_name) LIKE ? ESCAPE '!'", [$pattern])));
        });
    }

    protected function matchField(Model $model, string $query): string
    {
        foreach ($this->fields() as $field) {
            if (SearchText::contains($model->{$field}, $query)) {
                return $field;
            }
        }
        foreach ($model->attachments as $attachment) {
            if (SearchText::contains($attachment->original_name, $query)) {
                return 'original_name';
            }
            if (SearchText::contains($attachment->url, $query)) {
                return 'url';
            }
        }

        return 'title';
    }

    protected function subtitle(Model $model, string $query): ?string
    {
        return match ($this->matchField($model, $query)) {
            'content_text' => 'Matched in a note',
            'original_name' => 'Matched in a file',
            'url' => 'Matched in a link',
            default => SearchText::context($model->description) ?? 'Resource',
        };
    }

    protected function archived(Model $model): bool
    {
        return $model->archived_at !== null;
    }
}
