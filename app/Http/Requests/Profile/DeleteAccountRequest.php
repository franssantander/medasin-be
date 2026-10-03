<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', Rule::in(['DELETE'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirmation.in' => 'Type DELETE to confirm permanent account deletion.'];
    }
}
