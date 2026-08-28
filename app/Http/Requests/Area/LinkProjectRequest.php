<?php

namespace App\Http\Requests\Area;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_uuid' => [
                'required',
                'uuid',
                Rule::exists('projects', 'uuid')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('deleted_at')),
            ],
        ];
    }
}
