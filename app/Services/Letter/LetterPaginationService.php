<?php

namespace App\Services\Letter;

use App\Enum\LetterExportFormat;
use App\Models\Letter;
use Illuminate\Support\Str;

class LetterPaginationService
{
    public function __construct(
        private readonly LetterContentService $content,
    ) {}

    /**
     * @return array<string, int>
     */
    public function profile(LetterExportFormat $format): array
    {
        /** @var array<string, int> $profile */
        $profile = config('letters.profiles.'.$format->value, []);

        return $profile;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paginate(Letter $letter, LetterExportFormat $format): array
    {
        $profile = $this->profile($format);
        $blocks = $this->content->blocks($letter->content);
        $contentPages = $this->splitBlocks($blocks, $profile['body_height'], $profile);
        $lastPage = array_pop($contentPages) ?? [];
        $contentPages = array_merge(
            $contentPages,
            $this->splitBlocks($lastPage, $profile['final_height'], $profile),
        );

        $truncated = false;
        $maxContentPages = (int) config('letters.max_pages', 10) - 1;
        if (count($contentPages) > $maxContentPages) {
            $keptPages = array_slice($contentPages, 0, $maxContentPages - 1);
            $remainingBlocks = $this->flattenPages(array_slice($contentPages, $maxContentPages - 1));
            $lastPage = $this->splitBlocks($remainingBlocks, $profile['final_height'], $profile)[0] ?? [];
            $contentPages = [...$keptPages, $lastPage];
            $truncated = true;
        }

        $pages = [[
            'uuid' => (string) Str::uuid(),
            'number' => 1,
            'kind' => 'cover',
            'layout' => 'cover',
            'text_scale' => 1.0,
            'text_scale_mode' => 'auto',
            'title' => $letter->title,
            'subtitle' => $letter->subtitle,
            'blocks' => [],
            'signature' => null,
            'truncated' => false,
            'continuation_label' => null,
        ]];

        foreach ($contentPages as $index => $blocks) {
            $isFinal = $index === array_key_last($contentPages);
            $pages[] = [
                'uuid' => (string) Str::uuid(),
                'number' => count($pages) + 1,
                'kind' => $isFinal ? 'final' : 'body',
                'layout' => 'body',
                'text_scale' => 1.0,
                'text_scale_mode' => 'auto',
                'title' => null,
                'subtitle' => null,
                'blocks' => array_values($blocks),
                'signature' => $isFinal ? $this->signature($letter) : null,
                'truncated' => $isFinal && $truncated,
                'continuation_label' => $isFinal && $truncated
                    ? config('letters.continuation_label')
                    : null,
            ];
        }

        return $pages;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, int>  $profile
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function splitBlocks(array $blocks, int $capacity, array $profile): array
    {
        $pages = [];
        $current = [];
        $height = 0;

        foreach ($blocks as $block) {
            $fragments = $this->splitBlock($block, $capacity, $profile);
            foreach ($fragments as $fragment) {
                $fragmentHeight = $this->blockHeight($fragment, $profile);
                if ($current !== [] && $height + $fragmentHeight > $capacity) {
                    $pages[] = $current;
                    $current = [];
                    $height = 0;
                }

                $current[] = $fragment;
                $height += $fragmentHeight;
            }
        }

        if ($current !== [] || $pages === []) {
            $pages[] = $current;
        }

        return $pages;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, int>  $profile
     * @return array<int, array<string, mixed>>
     */
    private function splitBlock(array $block, int $capacity, array $profile): array
    {
        if ($this->blockHeight($block, $profile) <= $capacity) {
            return [$block];
        }

        $text = $this->content->blockText($block);
        if ($text === '') {
            return [$block];
        }

        return array_map(
            fn (string $fragment): array => [
                ...$block,
                'content' => $fragment,
            ],
            $this->splitText($text, $capacity, $profile),
        );
    }

    /**
     * @param  array<string, int>  $profile
     * @return array<int, string>
     */
    private function splitText(string $text, int $capacity, array $profile): array
    {
        $sentences = preg_split('/(?<=[.!?…])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $fragments = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $candidate = trim($current === '' ? $sentence : $current.' '.$sentence);
            if ($this->textHeight($candidate, $profile) <= $capacity) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $fragments[] = $current;
                $current = '';
            }

            if ($this->textHeight($sentence, $profile) > $capacity) {
                $fragments = [...$fragments, ...$this->splitWords($sentence, $capacity, $profile)];
            } else {
                $current = trim($sentence);
            }
        }

        if ($current !== '') {
            $fragments[] = $current;
        }

        return $fragments === [] ? [trim($text)] : $fragments;
    }

    /**
     * @param  array<string, int>  $profile
     * @return array<int, string>
     */
    private function splitWords(string $text, int $capacity, array $profile): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $fragments = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = trim($current === '' ? $word : $current.' '.$word);
            if ($current === '' || $this->textHeight($candidate, $profile) <= $capacity) {
                $current = $candidate;

                continue;
            }

            $fragments[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $fragments[] = $current;
        }

        return $fragments;
    }

    /**
     * @param  array<string, int>  $profile
     */
    private function blockHeight(array $block, array $profile): int
    {
        return $this->textHeight($this->content->blockText($block), $profile);
    }

    /**
     * @param  array<string, int>  $profile
     */
    private function textHeight(string $text, array $profile): int
    {
        $lines = $this->lineCount($text, $profile['line_width']);

        return ($lines * $profile['line_height']) + $profile['paragraph_gap'];
    }

    private function lineCount(string $text, int $lineWidth): int
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return 1;
        }

        $lines = 1;
        $currentWidth = 0;
        foreach ($words as $word) {
            $wordWidth = max(1, mb_strwidth($word));
            if ($currentWidth === 0) {
                $currentWidth = $wordWidth;

                continue;
            }
            if ($currentWidth + 1 + $wordWidth > $lineWidth) {
                $lines++;
                $currentWidth = $wordWidth;

                continue;
            }
            $currentWidth += 1 + $wordWidth;
        }

        return $lines;
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $pages
     * @return array<int, array<string, mixed>>
     */
    private function flattenPages(array $pages): array
    {
        $blocks = [];
        foreach ($pages as $page) {
            $blocks = [...$blocks, ...$page];
        }

        return $blocks;
    }

    /**
     * @return array{name: string, handle: string}
     */
    private function signature(Letter $letter): array
    {
        $user = $letter->user;

        return [
            'name' => trim($user->first_name.' '.$user->last_name),
            'handle' => '@'.$user->username,
        ];
    }
}
