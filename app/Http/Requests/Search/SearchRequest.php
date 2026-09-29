<?php

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        if (is_string($this->input('q'))) {
            $values['q'] = trim($this->input('q'));
        }
        if (in_array($this->input('include_archived'), ['true', 'false'], true)) {
            $values['include_archived'] = $this->input('include_archived') === 'true' ? '1' : '0';
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['sometimes', 'string', Rule::in([
                'project', 'area', 'resource', 'note', 'journal',
                'letter', 'plan', 'habit', 'goal',
            ])],
            'limit' => ['sometimes', 'integer', 'between:1,10'],
            'include_archived' => ['sometimes', 'boolean'],
        ];
    }
}
