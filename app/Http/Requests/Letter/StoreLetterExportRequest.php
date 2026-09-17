<?php

namespace App\Http\Requests\Letter;

use App\Enum\LetterExportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLetterExportRequest extends FormRequest
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
            'format' => [
                'sometimes',
                Rule::in(array_column(LetterExportFormat::cases(), 'value')),
            ],
            'pages' => ['sometimes', 'array', 'list', 'min:2'],
            'pages.*' => ['required', 'array:uuid,layout,text_scale,text_scale_mode,title,subtitle,blocks'],
            'pages.*.uuid' => ['required_with:pages', 'uuid', 'distinct'],
            'pages.*.layout' => ['required_with:pages', Rule::in(['cover', 'body', 'quote'])],
            'pages.*.text_scale' => ['sometimes', 'numeric', 'between:0.1,1.4'],
            'pages.*.text_scale_mode' => ['sometimes', Rule::in(['auto', 'manual'])],
            'pages.*.title' => ['nullable', 'string', 'max:120'],
            'pages.*.subtitle' => ['nullable', 'string', 'max:240'],
            'pages.*.blocks' => ['present_with:pages', 'array'],
            'pages.*.blocks.*' => ['array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $pages = $this->input('pages');
                if ($validator->errors()->has('pages') || ! is_array($pages)) {
                    return;
                }

                if (($pages[0]['layout'] ?? null) !== 'cover') {
                    $validator->errors()->add('pages.0.layout', 'The first page must be the cover.');
                }

                foreach (array_slice($pages, 1) as $index => $page) {
                    if (($page['layout'] ?? null) === 'cover') {
                        $validator->errors()->add('pages.'.($index + 1).'.layout', 'Only the first page may be the cover.');
                    }
                }
            },
        ];
    }
}
