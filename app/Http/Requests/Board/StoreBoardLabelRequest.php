<?php

namespace App\Http\Requests\Board;

use App\Enum\BoardLabelColor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardLabelRequest extends FormRequest
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
        $board = $this->route('board');

        return [
            'name' => ['required', 'string', 'max:50', Rule::unique('board_labels')->where('board_id', $board?->getKey())],
            'color' => ['required', Rule::enum(BoardLabelColor::class)],
        ];
    }
}
