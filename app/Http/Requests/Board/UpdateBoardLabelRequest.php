<?php

namespace App\Http\Requests\Board;

use App\Enum\BoardLabelColor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardLabelRequest extends FormRequest
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
        $label = $this->route('label');

        return [
            'name' => ['sometimes', 'string', 'max:50', Rule::unique('board_labels')->ignore($label?->getKey())->where('board_id', $board?->getKey())],
            'color' => ['sometimes', Rule::enum(BoardLabelColor::class)],
        ];
    }
}
