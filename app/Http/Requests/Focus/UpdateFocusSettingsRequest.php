<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFocusSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'focus_minutes' => ['required', 'integer', 'between:1,120'],
            'short_break_minutes' => ['required', 'integer', 'between:1,60'],
            'long_break_minutes' => ['required', 'integer', 'between:1,60'],
            'sessions_before_long_break' => ['required', 'integer', 'between:1,12'],
            'ask_before_next_session' => ['required', 'boolean'],
            'ask_for_reflection' => ['required', 'boolean'],
            'ambient_sound' => ['required', Rule::in(['off', 'brown', 'pink', 'white'])],
        ];
    }
}
