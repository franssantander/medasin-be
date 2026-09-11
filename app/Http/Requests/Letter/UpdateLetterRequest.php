<?php

namespace App\Http\Requests\Letter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLetterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:240'],
            'content' => ['sometimes', 'required', 'string', 'json'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateDocument($validator);
            },
        ];
    }

    private function validateDocument(Validator $validator): void
    {
        $content = $this->input('content');
        if ($validator->errors()->has('content') || ! is_string($content)) {
            return;
        }

        $document = json_decode($content, true);
        if (! is_array($document)
            || ($document['version'] ?? null) !== 1
            || ! isset($document['blocks'])
            || ! is_array($document['blocks'])
            || collect($document['blocks'])->contains(fn (mixed $block): bool => ! is_array($block))
        ) {
            $validator->errors()->add('content', 'Content must be a version 1 BlockNote document.');
        }
    }
}
