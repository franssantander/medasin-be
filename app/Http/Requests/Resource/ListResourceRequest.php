<?php

namespace App\Http\Requests\Resource;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['note', 'link', 'image', 'file'])],
            'tag_uuid' => ['sometimes', 'uuid', Rule::exists('resource_tags', 'uuid')->where('user_id', $this->user()->id)],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
        ];
    }
}
