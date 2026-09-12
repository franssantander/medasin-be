<?php

namespace App\Http\Requests\Letter;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLetterExportRequest extends FormRequest
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
            'pages' => ['required', 'array', 'list', 'between:2,10'],
            'pages.*' => ['required', 'array:uuid,layout,text_scale,title,subtitle,blocks'],
            'pages.*.uuid' => ['required', 'uuid', 'distinct'],
            'pages.*.layout' => ['required', Rule::in(['cover', 'body', 'quote'])],
            'pages.*.text_scale' => ['sometimes', 'numeric', 'between:0.75,1.4'],
            'pages.*.title' => ['nullable', 'string', 'max:120'],
            'pages.*.subtitle' => ['nullable', 'string', 'max:240'],
            'pages.*.blocks' => ['present', 'array'],
            'pages.*.blocks.*' => ['array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('pages')) {
                    return;
                }

                $pages = $this->input('pages');
                if (! is_array($pages)) {
                    return;
                }

                if (($pages[0]['layout'] ?? null) !== 'cover') {
                    $validator->errors()->add('pages.0.layout', 'The first page must be the cover.');
                }

                foreach (array_slice($pages, 1) as $index => $page) {
                    if (is_array($page) && ($page['layout'] ?? null) === 'cover') {
                        $validator->errors()->add('pages.'.($index + 1).'.layout', 'Only the first page may be the cover.');
                    }
                }
            },
        ];
    }
}
