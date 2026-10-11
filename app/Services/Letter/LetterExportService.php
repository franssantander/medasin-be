<?php

namespace App\Services\Letter;

use App\Enum\LetterExportFormat;
use App\Enum\LetterExportStatus;
use App\Enum\LetterStatus;
use App\Jobs\Letter\GenerateLetterExport;
use App\Models\Letter;
use App\Models\LetterExport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LetterExportService
{
    /** Blog-post order: kicker, title, excerpt, byline, then the image. */
    private const COVER_SECTIONS = ['header', 'title', 'entry', 'author', 'hero'];

    public function __construct(
        private readonly LetterPaginationService $pagination,
        private readonly LetterContentService $content,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>|null  $pages
     */
    public function create(Letter $letter, LetterExportFormat $format, ?array $pages = null): LetterExport
    {
        $profile = $this->pagination->profile($format);

        $export = DB::transaction(function () use ($letter, $format, $profile, $pages): LetterExport {
            $preparedPages = $pages === null
                ? null
                : $this->normalizePages(
                    $pages,
                    $this->signature($letter),
                    null,
                    $this->defaultCover($letter),
                );
            $export = $letter->exports()->create([
                'format' => $format,
                'canvas_width' => $profile['width'],
                'canvas_height' => $profile['height'],
                'source_hash' => $letter->sourceHash(),
                'status' => $preparedPages === null
                    ? LetterExportStatus::QUEUED
                    : LetterExportStatus::READY,
                'pages' => $preparedPages,
                'page_count' => $preparedPages === null ? null : count($preparedPages),
                'started_at' => $preparedPages === null ? null : now(),
                'completed_at' => $preparedPages === null ? null : now(),
            ]);

            if ($preparedPages === null) {
                GenerateLetterExport::dispatch($export)->afterCommit();
            } else {
                $letter->forceFill([
                    'status' => LetterStatus::EXPORTED,
                    'exported_at' => now(),
                ])->save();
            }

            return $export;
        });

        return $this->find($letter, $export);
    }

    public function listing(Letter $letter, int $perPage = 15): LengthAwarePaginator
    {
        return $letter->exports()
            ->with('letter')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(Letter $letter, LetterExport $export): LetterExport
    {
        return $letter->exports()
            ->with('letter')
            ->whereKey($export->getKey())
            ->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function updatePages(Letter $letter, LetterExport $export, array $pages): LetterExport
    {
        $export = $this->find($letter, $export);
        if ($export->status !== LetterExportStatus::READY) {
            throw new ConflictHttpException('Only a ready letter export can be customized.');
        }

        $existingPages = $export->pages ?? [];
        $signature = collect($existingPages)->firstWhere('signature', '!=', null)['signature'] ?? null;
        $lastPage = $pages[array_key_last($pages)];
        if (array_key_exists('signature', $lastPage)) {
            $signature = [
                'name' => $lastPage['signature']['name'] ?? '',
                'handle' => $lastPage['signature']['handle'] ?? '',
            ];
        }
        $truncatedPage = collect($existingPages)->firstWhere('truncated', true);
        $defaultCover = array_replace(
            $this->defaultCover($letter),
            $existingPages[0]['cover'] ?? [],
        );
        $normalizedPages = $this->normalizePages($pages, $signature, $truncatedPage, $defaultCover);

        DB::transaction(function () use ($letter, $export, $normalizedPages): void {
            $cover = $normalizedPages[0];
            $title = trim((string) ($cover['title'] ?? ''));
            $subtitle = trim($this->content->textForBlocks(
                $cover['cover']['description_blocks'] ?? [],
            ));
            $blocks = collect(array_slice($normalizedPages, 1))
                ->reject(fn (array $page): bool => ($page['content_source'] ?? 'letter_body') === 'cover_entry')
                ->flatMap(fn (array $page): array => $page['blocks'])
                ->values()
                ->all();
            $content = json_encode([
                'version' => 1,
                'blocks' => $blocks,
            ], JSON_THROW_ON_ERROR);
            $contentText = $this->content->text($content);
            $wordCount = $this->content->wordCount($contentText);

            $letter->forceFill([
                'title' => $title !== '' ? $title : 'Untitled letter',
                'subtitle' => $subtitle !== '' ? Str::limit($subtitle, 240, '') : null,
                'content' => $content,
                'content_text' => $contentText !== '' ? $contentText : null,
                'word_count' => $wordCount,
                'read_time_minutes' => $this->content->readTimeMinutes($wordCount),
                'status' => LetterStatus::DRAFT,
                'exported_at' => null,
            ])->save();

            $export->forceFill([
                'pages' => $normalizedPages,
                'page_count' => count($normalizedPages),
                'source_hash' => $letter->sourceHash(),
            ])->save();
        });

        return $this->find($letter, $export);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<string, string>|null  $signature
     * @param  array<string, mixed>|null  $truncatedPage
     * @param  array<string, mixed>  $defaultCover
     * @return array<int, array<string, mixed>>
     */
    private function normalizePages(
        array $pages,
        ?array $signature,
        ?array $truncatedPage,
        array $defaultCover,
    ): array {
        $lastIndex = array_key_last($pages);
        $sharedTextScale = (float) ($pages[1]['text_scale'] ?? 1);
        $sharedTextScaleMode = $pages[1]['text_scale_mode'] ?? 'auto';

        return array_map(
            function (array $page, int $index) use ($lastIndex, $signature, $truncatedPage, $defaultCover, $sharedTextScale, $sharedTextScaleMode): array {
                $isCover = $index === 0;
                $isFinal = $index === $lastIndex;
                $normalizedCover = $isCover
                    ? $this->normalizeCover($page['cover'] ?? $defaultCover, $defaultCover)
                    : null;

                return [
                    'uuid' => $page['uuid'],
                    'number' => $index + 1,
                    'kind' => $isCover ? 'cover' : ($isFinal ? 'final' : 'body'),
                    'layout' => $page['layout'],
                    'text_scale' => $isCover ? (float) ($page['text_scale'] ?? 1) : $sharedTextScale,
                    'text_scale_mode' => $isCover ? ($page['text_scale_mode'] ?? 'auto') : $sharedTextScaleMode,
                    'title' => $isCover ? ($page['title'] ?? null) : null,
                    'subtitle' => $isCover
                        ? (Str::limit($this->content->textForBlocks($normalizedCover['description_blocks']), 240, '') ?: null)
                        : null,
                    'content_source' => $page['content_source'] ?? ($isCover ? null : 'letter_body'),
                    'cover' => $normalizedCover,
                    'blocks' => array_values($page['blocks']),
                    'signature' => $isFinal ? $signature : null,
                    'truncated' => $isFinal && $truncatedPage !== null,
                    'continuation_label' => ($page['content_source'] ?? null) === 'cover_entry'
                        ? ($page['continuation_label'] ?? 'Cover entry · Continued')
                        : ($isFinal ? ($truncatedPage['continuation_label'] ?? null) : null),
                ];
            },
            $pages,
            array_keys($pages),
        );
    }

    /**
     * @param  array<string, mixed>  $cover
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function normalizeCover(array $cover, array $fallback): array
    {
        $heroImageUrl = array_key_exists('hero_image_url', $cover)
            ? $cover['hero_image_url']
            : $fallback['hero_image_url'];

        return [
            'theme' => $cover['theme'] ?? $fallback['theme'],
            'show_logo' => (bool) ($cover['show_logo'] ?? $fallback['show_logo']),
            'text_alignment' => $cover['text_alignment'] ?? $fallback['text_alignment'],
            'subheader' => (string) ($cover['subheader'] ?? $fallback['subheader']),
            'subheader_font_scale' => (float) ($cover['subheader_font_scale'] ?? $fallback['subheader_font_scale']),
            'title_font_scale' => (float) ($cover['title_font_scale'] ?? $fallback['title_font_scale']),
            'body_font_scale' => (float) ($cover['body_font_scale'] ?? $fallback['body_font_scale']),
            'description_blocks' => array_values(
                $cover['description_blocks'] ?? $fallback['description_blocks'],
            ),
            'author_name' => (string) ($cover['author_name'] ?? $fallback['author_name']),
            'author_handle' => (string) ($cover['author_handle'] ?? $fallback['author_handle']),
            'date_label' => (string) ($cover['date_label'] ?? $fallback['date_label']),
            'avatar_url' => array_key_exists('avatar_url', $cover)
                ? $cover['avatar_url']
                : $fallback['avatar_url'],
            'show_author' => (bool) ($cover['show_author'] ?? $fallback['show_author']),
            'byline_placement' => $cover['byline_placement'] ?? $fallback['byline_placement'],
            'show_date' => (bool) ($cover['show_date'] ?? $fallback['show_date']),
            'show_page_numbers' => (bool) ($cover['show_page_numbers'] ?? $fallback['show_page_numbers']),
            'show_signature' => (bool) ($cover['show_signature'] ?? $fallback['show_signature']),
            'hero_image_url' => $heroImageUrl,
            'hero_image_original_url' => $heroImageUrl
                ? (array_key_exists('hero_image_original_url', $cover)
                    ? $cover['hero_image_original_url']
                    : ($fallback['hero_image_original_url'] ?? null))
                : null,
            'hero_image_crop' => $heroImageUrl
                ? $this->normalizeCoverImageCrop(
                    array_key_exists('hero_image_crop', $cover)
                        ? $cover['hero_image_crop']
                        : ($fallback['hero_image_crop'] ?? null),
                )
                : null,
            'hero_image_aspect_ratio' => $heroImageUrl
                ? $this->normalizeCoverHeroAspectRatio(
                    $cover['hero_image_aspect_ratio']
                        ?? $fallback['hero_image_aspect_ratio']
                        ?? (16 / 9),
                )
                : null,
            'hero_image_caption' => $heroImageUrl
                ? (string) ($cover['hero_image_caption'] ?? $fallback['hero_image_caption'] ?? '')
                : '',
            'hero_image_caption_alignment' => $heroImageUrl
                ? ($cover['hero_image_caption_alignment'] ?? $fallback['hero_image_caption_alignment'] ?? 'center')
                : 'center',
            'hero_image_caption_placement' => $heroImageUrl
                ? ($cover['hero_image_caption_placement'] ?? $fallback['hero_image_caption_placement'] ?? 'overlay')
                : 'overlay',
            'section_order' => $this->normalizeCoverSectionOrder(
                $cover['section_order'] ?? $fallback['section_order'],
            ),
        ];
    }

    /** @param  array<int, mixed>  $sections */
    private function normalizeCoverSectionOrder(array $sections): array
    {
        // The author byline is reorderable like every other cover section.
        $normalized = collect($sections)
            ->flatMap(fn (mixed $section): array => $section === 'content'
                ? ['title', 'entry']
                : (in_array($section, self::COVER_SECTIONS, true) ? [$section] : []))
            ->merge(self::COVER_SECTIONS)
            ->unique()
            ->values();

        return $normalized->all();
    }

    /** @return array{x: float, y: float, width: float, height: float}|null */
    private function normalizeCoverImageCrop(mixed $crop): ?array
    {
        if (! is_array($crop)) {
            return null;
        }

        $fraction = fn (string $key): float => min(1, max(0, (float) ($crop[$key] ?? 0)));

        return [
            'x' => $fraction('x'),
            'y' => $fraction('y'),
            'width' => $fraction('width'),
            'height' => $fraction('height'),
        ];
    }

    private function normalizeCoverHeroAspectRatio(mixed $value): float
    {
        $ratio = is_numeric($value) ? (float) $value : (16 / 9);

        return min(10, max(0.1, $ratio));
    }

    /** @return array<string, mixed> */
    private function defaultCover(Letter $letter): array
    {
        return [
            'theme' => 'light',
            'show_logo' => true,
            'text_alignment' => 'center',
            'subheader' => 'A LETTER',
            'subheader_font_scale' => 1.0,
            'title_font_scale' => 1.0,
            'body_font_scale' => 1.0,
            'description_blocks' => [[
                'type' => 'paragraph',
                'content' => (string) ($letter->subtitle ?? ''),
            ]],
            'author_name' => trim($letter->user->first_name.' '.$letter->user->last_name),
            'author_handle' => '@'.$letter->user->username,
            'date_label' => $letter->created_at?->format('M j, Y') ?? '',
            'avatar_url' => null,
            'show_author' => true,
            'byline_placement' => 'top',
            'show_date' => true,
            'show_page_numbers' => true,
            'show_signature' => true,
            'hero_image_url' => null,
            'hero_image_original_url' => null,
            'hero_image_crop' => null,
            'hero_image_aspect_ratio' => null,
            'hero_image_caption' => '',
            'hero_image_caption_alignment' => 'center',
            'hero_image_caption_placement' => 'overlay',
            'section_order' => self::COVER_SECTIONS,
        ];
    }

    /** @return array{name: string, handle: string} */
    private function signature(Letter $letter): array
    {
        $user = $letter->user;

        return [
            'name' => trim($user->first_name.' '.$user->last_name),
            'handle' => '@'.$user->username,
        ];
    }
}
