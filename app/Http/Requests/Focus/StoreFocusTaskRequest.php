<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;

class StoreFocusTaskRequest extends FormRequest
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
            'title' => ['required_without:board_task_uuid', 'nullable', 'string', 'max:120'],
            'board_task_uuid' => ['nullable', 'uuid'],
        ];
    }
}
