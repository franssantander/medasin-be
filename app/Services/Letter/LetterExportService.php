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

        return array_map(
            function (array $page, int $index) use ($lastIndex, $signature, $truncatedPage, $defaultCover): array {
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
                    'text_scale' => (float) ($page['text_scale'] ?? 1),
                    'text_scale_mode' => $page['text_scale_mode'] ?? 'auto',
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
            'description_blocks' => array_values(
                $cover['description_blocks'] ?? $fallback['description_blocks'],
            ),
            'author_name' => (string) ($cover['author_name'] ?? $fallback['author_name']),
            'date_label' => (string) ($cover['date_label'] ?? $fallback['date_label']),
            'avatar_url' => array_key_exists('avatar_url', $cover)
                ? $cover['avatar_url']
                : $fallback['avatar_url'],
            'hero_image_url' => $heroImageUrl,
            'hero_image_aspect_ratio' => $heroImageUrl
                ? $this->normalizeCoverHeroAspectRatio(
                    $cover['hero_image_aspect_ratio']
                        ?? $fallback['hero_image_aspect_ratio']
                        ?? (16 / 9),
                )
                : null,
            'section_order' => $this->normalizeCoverSectionOrder(
                $cover['section_order'] ?? $fallback['section_order'],
            ),
        ];
    }

    /** @param  array<int, mixed>  $sections */
    private function normalizeCoverSectionOrder(array $sections): array
    {
        $normalized = collect($sections)
            ->flatMap(fn (mixed $section): array => $section === 'content'
                ? ['title', 'entry']
                : (in_array($section, ['header', 'title', 'entry', 'author', 'hero'], true) ? [$section] : []))
            ->reject(fn (string $section): bool => $section === 'author')
            ->merge(['header', 'title', 'entry', 'hero'])
            ->unique()
            ->values()
            ->take(4)
            ->push('author');

        return $normalized->all();
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
            'description_blocks' => [[
                'type' => 'paragraph',
                'content' => (string) ($letter->subtitle ?? ''),
            ]],
            'author_name' => trim($letter->user->first_name.' '.$letter->user->last_name),
            'date_label' => $letter->created_at?->format('F j \\a\\t g:i A') ?? '',
            'avatar_url' => null,
            'hero_image_url' => null,
            'hero_image_aspect_ratio' => null,
            'section_order' => ['header', 'title', 'entry', 'hero', 'author'],
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
