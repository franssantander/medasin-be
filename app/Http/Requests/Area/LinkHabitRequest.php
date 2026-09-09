<?php

namespace App\Http\Requests\Area;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkHabitRequest extends FormRequest
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
        return ['habit_uuid' => ['required', 'uuid', Rule::exists('habits', 'uuid')->where(fn ($q) => $q->where('user_id', $this->user()->getKey())->whereNull('deleted_at'))]];
    }
}
