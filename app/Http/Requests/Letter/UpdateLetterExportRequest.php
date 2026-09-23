<?php

namespace App\Http\Requests\Letter;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLetterExportRequest extends FormRequest
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
            'pages' => ['required', 'array', 'list', 'min:2'],
            'pages.*' => ['required', 'array:uuid,layout,text_scale,text_scale_mode,title,subtitle,cover,content_source,blocks,signature'],
            'pages.*.uuid' => ['required', 'uuid', 'distinct'],
            'pages.*.layout' => ['required', Rule::in(['cover', 'body', 'quote'])],
            'pages.*.text_scale' => ['sometimes', 'numeric', 'between:0.1,1.4'],
            'pages.*.text_scale_mode' => ['sometimes', Rule::in(['auto', 'manual'])],
            'pages.*.title' => ['nullable', 'string', 'max:120'],
            'pages.*.subtitle' => ['nullable', 'string', 'max:240'],
            'pages.*.content_source' => ['sometimes', 'nullable', Rule::in(['cover_entry', 'letter_body'])],
            'pages.*.signature' => ['sometimes', 'array:name,handle'],
            'pages.*.signature.name' => ['present_with:pages.*.signature', 'nullable', 'string', 'max:120'],
            'pages.*.signature.handle' => ['present_with:pages.*.signature', 'nullable', 'string', 'max:80'],
            'pages.*.cover' => ['nullable', 'array:theme,show_logo,text_alignment,subheader,description_blocks,author_name,date_label,avatar_url,hero_image_url,hero_image_aspect_ratio,section_order'],
            'pages.*.cover.theme' => ['required_with:pages.*.cover', Rule::in(['light', 'dark'])],
            'pages.*.cover.show_logo' => ['required_with:pages.*.cover', 'boolean'],
            'pages.*.cover.text_alignment' => ['sometimes', Rule::in(['left', 'center', 'right'])],
            'pages.*.cover.subheader' => ['present_with:pages.*.cover', 'nullable', 'string', 'max:80'],
            'pages.*.cover.description_blocks' => ['required_with:pages.*.cover', 'array', 'list'],
            'pages.*.cover.description_blocks.*' => ['array'],
            'pages.*.cover.author_name' => ['present_with:pages.*.cover', 'nullable', 'string', 'max:120'],
            'pages.*.cover.date_label' => ['present_with:pages.*.cover', 'nullable', 'string', 'max:80'],
            'pages.*.cover.avatar_url' => ['present_with:pages.*.cover', 'nullable', 'string', 'max:2048'],
            'pages.*.cover.hero_image_url' => ['present_with:pages.*.cover', 'nullable', 'string', 'max:2048'],
            'pages.*.cover.hero_image_aspect_ratio' => ['sometimes', 'nullable', 'numeric', 'between:0.1,10'],
            'pages.*.cover.section_order' => ['required_with:pages.*.cover', 'array', 'list', 'between:4,5'],
            'pages.*.cover.section_order.*' => ['required', 'string', 'distinct', Rule::in(['header', 'content', 'title', 'entry', 'author', 'hero'])],
            'pages.*.blocks' => ['present', 'array'],
            'pages.*.blocks.*' => ['array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('pages')) {
                    return;
                }

                $pages = $this->input('pages');
                if (! is_array($pages)) {
                    return;
                }

                if (($pages[0]['layout'] ?? null) !== 'cover') {
                    $validator->errors()->add('pages.0.layout', 'The first page must be the cover.');
                }

                foreach (array_slice($pages, 1) as $index => $page) {
                    if (is_array($page) && ($page['layout'] ?? null) === 'cover') {
                        $validator->errors()->add('pages.'.($index + 1).'.layout', 'Only the first page may be the cover.');
                    }
                }
                foreach (array_slice($pages, 0, -1) as $index => $page) {
                    if (is_array($page) && array_key_exists('signature', $page)) {
                        $validator->errors()->add('pages.'.$index.'.signature', 'Only the last page may have author details.');
                    }
                }
                $descriptionBlocks = $pages[0]['cover']['description_blocks'] ?? [];
                foreach (is_array($descriptionBlocks) ? $descriptionBlocks : [] as $index => $block) {
                    if (! is_array($block) || ! in_array($block['type'] ?? null, $this->descriptionBlockTypes(), true)) {
                        $validator->errors()->add(
                            "pages.0.cover.description_blocks.{$index}.type",
                            'The cover entry may only contain text blocks.',
                        );
                    }
                }
            },
        ];
    }

    /** @return array<int, string> */
    private function descriptionBlockTypes(): array
    {
        return ['paragraph', 'heading', 'bulletListItem', 'numberedListItem', 'checkListItem', 'quote'];
    }
}
