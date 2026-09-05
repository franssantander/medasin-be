<?php

namespace App\Http\Requests\Resource;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('content'))) {
            $content = json_decode($this->input('content'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($content)) {
                $this->merge(['content' => $content]);
            }
        }
    }

    public function rules(): array
    {
        $owned = fn (string $table) => Rule::exists($table, 'uuid')->where('user_id', $this->user()->id);

        return [
            'title' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'background' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'content' => ['nullable', 'array'],
            'content.type' => ['required_with:content', 'in:doc'],
            'content.content' => ['sometimes', 'array'],
            'links' => ['sometimes', 'array', 'max:100'],
            'links.*' => ['required', 'url:http,https', 'max:4096'],
            'files' => ['sometimes', 'array', 'max:10'],
            'files.*' => ['required', 'file', 'max:20480'],
            'tag_uuids' => ['sometimes', 'array', 'max:100'],
            'tag_uuids.*' => ['required', 'uuid', 'distinct', $owned('resource_tags')],
            'tag_names' => ['sometimes', 'array', 'max:100'],
            'tag_names.*' => ['required', 'string', 'max:100'],
            'project_uuid' => ['nullable', 'uuid', $owned('projects')->whereNull('deleted_at')->whereNull('archived_at')],
            'area_uuid' => ['nullable', 'uuid', $owned('areas')->whereNull('deleted_at')->whereNull('archived_at')],
        ];
    }
}
