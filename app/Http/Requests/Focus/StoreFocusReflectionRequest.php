<?php

namespace App\Http\Requests\Focus;

use App\Enum\FocusMood;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFocusReflectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mood' => ['nullable', Rule::enum(FocusMood::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
