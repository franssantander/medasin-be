<?php

namespace App\Http\Requests\Board;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardRequest extends FormRequest
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
        $board = $this->route('board');

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('boards')->ignore($board?->getKey())->where(fn ($query) => $project
                    ? $query->where('context_type', 'project')->where('context_id', $project->getKey())->whereNull('deleted_at')
                    : $query->whereNull('context_type')->whereNull('context_id')->where('user_id', $this->user()?->getKey())->whereNull('deleted_at')),
            ],
        ];
    }
}
