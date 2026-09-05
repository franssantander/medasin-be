<?php

namespace App\Http\Requests\Resource;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'tag_uuids' => ['sometimes', 'array', 'max:100'],
            'tag_uuids.*' => ['required', 'uuid', 'distinct', $owned('resource_tags')],
            'tag_names' => ['sometimes', 'array', 'max:100'],
            'tag_names.*' => ['required', 'string', 'max:100'],
            'project_uuid' => ['nullable', 'uuid', $owned('projects')->whereNull('deleted_at')->whereNull('archived_at')],
            'area_uuid' => ['nullable', 'uuid', $owned('areas')->whereNull('deleted_at')->whereNull('archived_at')],
        ];
    }
}
