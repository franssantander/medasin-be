<?php

namespace App\Http\Requests\Project;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('area_name'))) {
            $this->merge(['area_name' => trim($this->input('area_name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'area_uuid' => [
                'nullable',
                'uuid',
                'required_without:area_name',
                'prohibits:area_name',
                Rule::exists('areas', 'uuid')->where(
                    fn ($query) => $query
                        ->where('user_id', $this->user()->getKey())
                        ->whereNull('archived_at')
                        ->whereNull('deleted_at'),
                ),
            ],
            'area_name' => [
                'nullable',
                'string',
                'max:120',
                'required_without:area_uuid',
                'prohibits:area_uuid',
            ],
        ];
    }
}
