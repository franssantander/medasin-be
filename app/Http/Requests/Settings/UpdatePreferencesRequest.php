<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'font_family' => ['required', 'string', Rule::in(['geist', 'newsreader', 'manrope', 'inter'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'font_family.required' => 'Choose a font.',
            'font_family.string' => 'Choose a valid font.',
            'font_family.in' => 'Choose Geist, Newsreader, Manrope, or Inter.',
        ];
    }
}
