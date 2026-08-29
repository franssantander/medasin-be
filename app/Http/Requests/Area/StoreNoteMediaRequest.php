<?php

namespace App\Http\Requests\Area;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreNoteMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'webm', 'mov'])
                    ->max('100mb'),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if ($file && str_starts_with((string) $file->getMimeType(), 'image/') && $file->getSize() > 10 * 1024 * 1024) {
                    $validator->errors()->add('file', 'Images may not be larger than 10 MB.');
                }
            },
        ];
    }
}
