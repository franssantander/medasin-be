<?php

namespace App\Http\Requests\Letter;

use App\Enum\LetterExportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLetterExportRequest extends FormRequest
{
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
            'format' => [
                'sometimes',
                Rule::in(array_column(LetterExportFormat::cases(), 'value')),
            ],
        ];
    }
}
