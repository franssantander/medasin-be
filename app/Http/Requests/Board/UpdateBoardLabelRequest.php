<?php

namespace App\Http\Requests\Board;

use App\Enum\BoardLabelColor;
use App\Http\Requests\Concerns\ValidatesUniqueName;
use App\Models\BoardLabel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBoardLabelRequest extends FormRequest
{
    use ValidatesUniqueName;

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

        return [
            'name' => ['sometimes', 'string', 'max:50'],
            'color' => ['sometimes', Rule::enum(BoardLabelColor::class)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $board = $this->route('board');
            if (! $this->has('name') || ! $board) {
                return;
            }

            $this->validateUniqueName(
                $validator,
                BoardLabel::query()->where('board_id', $board->getKey()),
                'name',
                $this->string('name')->toString(),
                'label',
                $this->route('label'),
            );
        }];
    }
}
