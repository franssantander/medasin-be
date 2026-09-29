<?php

namespace App\Services\Search;

class SearchText
{
    public static function pattern(string $query): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)).'%';
    }

    public static function contains(?string $value, string $query): bool
    {
        return $value !== null && mb_stripos($value, $query) !== false;
    }

    public static function fromDocument(mixed $document): ?string
    {
        if (is_string($document)) {
            $decoded = json_decode($document, true);
            if (is_array($decoded)) {
                $document = $decoded;
            }
        }

        $text = self::plain(self::walk($document));

        return $text !== '' ? $text : null;
    }

    public static function snippet(?string $value, string $query): ?string
    {
        $text = self::plain($value ?? '');
        if ($text === '') {
            return null;
        }

        $position = mb_stripos($text, $query);
        $start = $position === false ? 0 : max(0, $position - 40);
        $length = max(120, mb_strlen($query) + 50);
        $excerpt = mb_substr($text, $start, $length);

        return ($start > 0 ? '…' : '').$excerpt.(mb_strlen($text) > $start + $length ? '…' : '');
    }

    public static function context(?string $value): ?string
    {
        $text = self::plain($value ?? '');

        return $text !== '' ? mb_strimwidth($text, 0, 80, '…') : null;
    }

    private static function plain(string $value): string
    {
        $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function walk(mixed $value, bool $inline = false): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return '';
        }
        if (array_is_list($value)) {
            return implode($inline ? '' : ' ', array_map(
                fn (mixed $child): string => self::walk($child),
                $value,
            ));
        }
        if (isset($value['text']) && is_string($value['text'])) {
            return $value['text'];
        }

        $parts = [];
        foreach (['blocks', 'content', 'children', 'rows', 'cells'] as $key) {
            if (array_key_exists($key, $value)) {
                $joinInline = $key === 'content' && ! in_array($value['type'] ?? null, ['doc', 'table', 'tableRow'], true);
                $parts[] = self::walk($value[$key], $joinInline);
            }
        }
        if (isset($value['props']['label']) && is_string($value['props']['label'])) {
            $parts[] = $value['props']['label'];
        }

        return implode(' ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }
}
