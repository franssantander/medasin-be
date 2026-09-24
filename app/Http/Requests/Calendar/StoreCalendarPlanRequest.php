<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalendarPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => [Rule::requiredIf(! $this->boolean('is_all_day')), 'prohibited_if:is_all_day,true', 'date_format:H:i'],
            'is_all_day' => ['required', 'boolean'],
            'timezone' => ['required', 'string', 'timezone'],
            'project_uuid' => [
                'nullable', 'uuid', 'prohibits:area_uuid',
                Rule::exists('projects', 'uuid')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('archived_at')
                    ->whereNull('deleted_at')),
            ],
            'area_uuid' => [
                'nullable', 'uuid', 'prohibits:project_uuid',
                Rule::exists('areas', 'uuid')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('archived_at')
                    ->whereNull('deleted_at')),
            ],
            'reminder_offset_minutes' => ['nullable', 'integer', 'between:0,43200'],
        ];
    }
}
