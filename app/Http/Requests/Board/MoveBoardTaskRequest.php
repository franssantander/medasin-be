<?php

namespace App\Http\Requests\Board;

use App\Enum\BoardStageKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveBoardTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::enum(BoardStageKey::class)],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }
}
