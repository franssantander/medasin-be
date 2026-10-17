<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\Validator;

/**
 * Names are unique per owner including soft-deleted rows (the database index covers Trash),
 * so a clash with a trashed record gets its own message pointing the user to Trash.
 */
trait ValidatesUniqueName
{
    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query  Owner-scoped query; trashed rows are included here.
     */
    protected function validateUniqueName(
        Validator $validator,
        Builder|Relation $query,
        string $column,
        string $value,
        string $noun,
        ?Model $ignore = null,
        string $attribute = 'name',
    ): void {
        if ($validator->errors()->has($attribute)) {
            return;
        }

        if ($value === '') {
            $validator->errors()->add($attribute, "The {$noun} name must contain at least one letter or number.");

            return;
        }

        $conflict = $query->withTrashed()
            ->where($column, $value)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->first();

        if (! $conflict) {
            return;
        }

        $article = preg_match('/^[aeiou]/i', $noun) ? 'An' : 'A';
        $validator->errors()->add($attribute, $conflict->trashed()
            ? "{$article} {$noun} with this name is in Trash. Restore it or choose another name."
            : "{$article} {$noun} with this name already exists.");
    }
}
