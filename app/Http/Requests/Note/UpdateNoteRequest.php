<?php

namespace App\Http\Requests\Note;

use App\Rules\MaxBytes;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'content' => ['sometimes', 'required', 'string', new MaxBytes],
            'is_pinned' => ['sometimes', 'boolean'],
            'parent_uuid' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
