<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFocusTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:120'],
            'completed' => ['sometimes', 'boolean'],
        ];
    }
}
