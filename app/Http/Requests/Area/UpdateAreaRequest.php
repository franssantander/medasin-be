<?php

namespace App\Http\Requests\Area;

use App\Http\Requests\Concerns\ValidatesUniqueName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class UpdateAreaRequest extends FormRequest
{
    use ValidatesUniqueName;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:50'],
            'background' => ['nullable', 'string', 'max:32'],
            'background_image' => ['nullable', File::image()->max('5mb')],
            'description' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('name')) {
                return;
            }

            $this->validateUniqueName(
                $validator,
                $this->user()->areas(),
                'slug',
                Str::slug($this->string('name')->toString()),
                'area',
                $this->route('area'),
            );
        }];
    }
}
