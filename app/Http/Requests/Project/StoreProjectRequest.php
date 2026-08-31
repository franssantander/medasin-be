<?php

namespace App\Http\Requests\Project;

use App\Enum\Status;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = [];

        if (is_string($this->input('name'))) {
            $input['name'] = trim($this->input('name'));
        }

        if (is_string($this->input('area_name'))) {
            $input['area_name'] = trim($this->input('area_name'));
        }

        $this->merge($input);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:50'],
            'background' => ['nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::enum(Status::class)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'area_uuid' => [
                'nullable',
                'uuid',
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
                'prohibits:area_uuid',
            ],
        ];
    }
}
