<?php

namespace App\Http\Requests\Area;

use App\Enum\HabitFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHabitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'icon' => ['sometimes', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'frequency' => ['sometimes', Rule::enum(HabitFrequency::class)],
            'schedule' => ['nullable', 'array'],
            'schedule.days' => ['required_if:frequency,weekly,custom', 'array', 'min:1'],
            'schedule.days.*' => ['string', 'distinct', Rule::in(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'])],
            'schedule.dates' => ['required_if:frequency,monthly', 'array', 'min:1'],
            'schedule.dates.*' => ['integer', 'distinct', 'between:1,31'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
