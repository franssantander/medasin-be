<?php

namespace App\Http\Requests\Board;

use App\Enum\BoardStageKey;
use App\Enum\BoardTaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardTaskRequest extends FormRequest
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
        return $this->taskRules(false);
    }

    protected function taskRules(bool $updating): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', Rule::enum(BoardTaskPriority::class)],
            'stage' => ['sometimes', Rule::enum(BoardStageKey::class)],
            'position' => ['sometimes', 'integer', 'min:0'],
            'label_uuids' => ['sometimes', 'array'],
            'label_uuids.*' => ['uuid', 'distinct'],
            'resource_uuids' => ['sometimes', 'array'],
            'resource_uuids.*' => ['uuid', 'distinct'],
            'note_uuids' => ['sometimes', 'array'],
            'note_uuids.*' => ['uuid', 'distinct'],
        ];
    }
}
