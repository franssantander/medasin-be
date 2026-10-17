<?php

namespace App\Http\Requests\Concerns;

use App\Enum\HabitFrequency;
use Illuminate\Validation\Rule;

/** Shared by the standalone and Area-scoped habit requests so the two entry points cannot drift. */
trait HabitRules
{
    /** @return array<string, array<mixed>> */
    protected function habitRules(bool $partial): array
    {
        $weekdays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

        return [
            'name' => $partial ? ['sometimes', 'required', 'string', 'max:120'] : ['required', 'string', 'max:120'],
            'icon' => $partial ? ['sometimes', 'required', 'string', 'max:50'] : ['sometimes', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'frequency' => ['sometimes', Rule::enum(HabitFrequency::class)],
            'schedule' => ['sometimes', 'nullable', 'array'],
            'schedule.days' => $partial ? ['sometimes', 'array', 'min:1'] : ['required_if:frequency,weekly,custom', 'array', 'min:1'],
            'schedule.days.*' => ['string', 'distinct', Rule::in($weekdays)],
            'schedule.dates' => $partial ? ['sometimes', 'array', 'min:1'] : ['required_if:frequency,monthly', 'array', 'min:1'],
            'schedule.dates.*' => ['integer', 'distinct', 'between:1,31'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<mixed> */
    protected function activeAreaRule(): array
    {
        return [
            'sometimes',
            'nullable',
            'uuid',
            Rule::exists('areas', 'uuid')->where(
                fn ($query) => $query
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('archived_at')
                    ->whereNull('deleted_at'),
            ),
        ];
    }
}
