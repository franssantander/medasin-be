<?php

namespace App\Http\Requests\Area;

use App\Enum\GoalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::enum(GoalStatus::class)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'due_date'])) {
                return;
            }

            $goal = $this->route('goal');
            $startDate = $this->has('start_date') ? $this->input('start_date') : $goal?->start_date;
            $dueDate = $this->has('due_date') ? $this->input('due_date') : $goal?->due_date;

            if ($startDate && $dueDate && Carbon::parse($dueDate)->lt(Carbon::parse($startDate))) {
                $validator->errors()->add('due_date', 'The due date must be after or equal to the start date.');
            }
        }];
    }
}
