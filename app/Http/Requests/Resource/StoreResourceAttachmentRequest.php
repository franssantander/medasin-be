<?php

namespace App\Http\Requests\Resource;

use Illuminate\Foundation\Http\FormRequest;

class StoreResourceAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'links' => ['sometimes', 'array', 'max:100', 'required_without:files'],
            'links.*' => ['required', 'url:http,https', 'max:4096'],
            'files' => ['sometimes', 'array', 'max:10', 'required_without:links'],
            'files.*' => ['required', 'file', 'max:20480'],
        ];
    }
}
