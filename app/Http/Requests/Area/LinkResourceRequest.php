<?php

namespace App\Http\Requests\Area;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resource_uuid' => [
                'required',
                'uuid',
                Rule::exists('resources', 'uuid')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('deleted_at')),
            ],
        ];
    }
}
