<?php

namespace App\Services\Letter;

class LetterContentService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function blocks(string $content): array
    {
        $document = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($document) || ! is_array($document['blocks'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $document['blocks'],
            fn (mixed $block): bool => is_array($block),
        ));
    }

    public function text(string $content): string
    {
        $text = $this->textForBlocks($this->blocks($content));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function textForBlocks(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $text = $this->blockText($block);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    public function blockText(array $block): string
    {
        $parts = [];
        if (array_key_exists('content', $block)) {
            $parts[] = $this->inlineText($block['content']);
        }
        if (isset($block['children']) && is_array($block['children'])) {
            $parts[] = $this->textForBlocks(array_values(array_filter(
                $block['children'],
                fn (mixed $child): bool => is_array($child),
            )));
        }
        $props = $block['props'] ?? null;
        if (is_array($props) && isset($props['label']) && is_string($props['label'])) {
            $parts[] = $props['label'];
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_filter(
            $parts,
            fn (string $part): bool => $part !== '',
        ))));
    }

    public function wordCount(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
    }

    public function readTimeMinutes(int $wordCount): int
    {
        return $wordCount === 0
            ? 0
            : (int) ceil($wordCount / (int) config('letters.words_per_minute', 200));
    }

    private function inlineText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return '';
        }
        if (isset($value['text']) && is_string($value['text'])) {
            return $value['text'];
        }

        $parts = [];
        foreach (['content', 'children', 'rows', 'cells'] as $key) {
            if (array_key_exists($key, $value)) {
                $parts[] = $this->inlineText($value[$key]);
            }
        }
        foreach ($value as $key => $child) {
            if (is_int($key)) {
                $parts[] = $this->inlineText($child);
            }
        }

        return implode('', $parts);
    }
}
