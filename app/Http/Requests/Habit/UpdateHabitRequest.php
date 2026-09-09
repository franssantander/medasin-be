<?php

namespace App\Http\Requests\Habit;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHabitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:120'], 'frequency' => ['sometimes', 'string'], 'schedule' => ['sometimes', 'nullable', 'array'], 'is_active' => ['sometimes', 'boolean'], 'area_uuid' => ['sometimes', 'nullable', 'uuid']];
    }
}
