<?php

namespace App\Http\Requests\Board;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'name' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
                Rule::unique('boards')->where(fn ($query) => $query
                    ->where('context_type', 'project')
                    ->where('context_id', $project?->getKey())
                    ->whereNull('deleted_at')),
            ],
        ];
    }
}
