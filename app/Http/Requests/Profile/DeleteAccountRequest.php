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

    /**
     * Deletion needs proof of the account, not just a session: the current password, or for accounts
     * created through Google (which have no known password) a code from POST /profile/deletion-code.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', Rule::in(['DELETE'])],
            'current_password' => ['required_without:otp', 'nullable', 'string', 'max:1024'],
            'otp' => ['required_without:current_password', 'nullable', 'string', 'regex:/^[0-9]{6}$/D'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirmation.in' => 'Type DELETE to confirm permanent account deletion.',
            'current_password.required_without' => 'Enter your current password, or request a confirmation code by email.',
            'otp.required_without' => 'Enter your current password, or request a confirmation code by email.',
        ];
    }
}
