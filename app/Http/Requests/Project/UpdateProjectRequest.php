<?php

namespace App\Http\Requests\Project;

use App\Enum\Status;
use App\Http\Requests\Concerns\ValidatesUniqueName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
{
    use ValidatesUniqueName;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:50'],
            'background' => ['nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::enum(Status::class)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->route('project');

            if ($this->has('name')) {
                $this->validateUniqueName(
                    $validator,
                    $this->user()->projects(),
                    'slug',
                    Str::slug($this->string('name')->toString()),
                    'project',
                    $project,
                );
            }

            if ($validator->errors()->hasAny(['start_date', 'due_date'])) {
                return;
            }

            // Partial updates compare against the stored dates, not only the ones in this request.
            $startDate = $this->has('start_date') ? $this->input('start_date') : $project?->start_date;
            $dueDate = $this->has('due_date') ? $this->input('due_date') : $project?->due_date;

            if ($startDate && $dueDate && Carbon::parse($dueDate)->lt(Carbon::parse($startDate))) {
                $validator->errors()->add('due_date', 'The due date must be after or equal to the start date.');
            }
        }];
    }
}
