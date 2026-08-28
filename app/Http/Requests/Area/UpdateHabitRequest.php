<?php

namespace App\Http\Requests\Area;

use App\Enum\HabitFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHabitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'icon' => ['sometimes', 'required', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string'],
            'frequency' => ['sometimes', Rule::enum(HabitFrequency::class)],
            'schedule' => ['sometimes', 'nullable', 'array'],
            'schedule.days' => ['sometimes', 'array', 'min:1'],
            'schedule.days.*' => ['string', 'distinct', Rule::in(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'])],
            'schedule.dates' => ['sometimes', 'array', 'min:1'],
            'schedule.dates.*' => ['integer', 'distinct', 'between:1,31'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
