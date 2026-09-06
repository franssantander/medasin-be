<?php

namespace App\Http\Requests\Focus;

use App\Enum\FocusSessionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartFocusSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(FocusSessionType::class)],
            'focus_task_uuid' => ['nullable', 'uuid'],
        ];
    }
}
