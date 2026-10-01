<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordByteLimit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
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
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8), new PasswordByteLimit],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
