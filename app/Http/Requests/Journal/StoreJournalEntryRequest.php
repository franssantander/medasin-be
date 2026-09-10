<?php

namespace App\Http\Requests\Journal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'json'],
            'resource_uuids' => ['sometimes', 'array', 'max:100'],
            'resource_uuids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('resources', 'uuid')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('archived_at')
                    ->whereNull('deleted_at')),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $content = $this->input('content');
                if ($validator->errors()->has('content') || ! is_string($content)) {
                    return;
                }

                $document = json_decode($content, true);
                if (! is_array($document)
                    || ($document['version'] ?? null) !== 1
                    || ! array_key_exists('blocks', $document)
                    || ! is_array($document['blocks'])
                ) {
                    $validator->errors()->add('content', 'Content must be a version 1 BlockNote document.');
                }
            },
        ];
    }
}
