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
                : $this->normalizePages($pages, $this->signature($letter));
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
        $truncatedPage = collect($existingPages)->firstWhere('truncated', true);
        $normalizedPages = $this->normalizePages($pages, $signature, $truncatedPage);

        DB::transaction(function () use ($letter, $export, $normalizedPages): void {
            $cover = $normalizedPages[0];
            $title = trim((string) ($cover['title'] ?? ''));
            $subtitle = trim((string) ($cover['subtitle'] ?? ''));
            $blocks = collect(array_slice($normalizedPages, 1))
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
                'subtitle' => $subtitle !== '' ? $subtitle : null,
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
     * @return array<int, array<string, mixed>>
     */
    private function normalizePages(array $pages, ?array $signature, ?array $truncatedPage = null): array
    {
        $lastIndex = array_key_last($pages);

        return array_map(
            function (array $page, int $index) use ($lastIndex, $signature, $truncatedPage): array {
                $isCover = $index === 0;
                $isFinal = $index === $lastIndex;

                return [
                    'uuid' => $page['uuid'],
                    'number' => $index + 1,
                    'kind' => $isCover ? 'cover' : ($isFinal ? 'final' : 'body'),
                    'layout' => $page['layout'],
                    'text_scale' => (float) ($page['text_scale'] ?? 1),
                    'text_scale_mode' => $page['text_scale_mode'] ?? 'auto',
                    'title' => $isCover ? ($page['title'] ?? null) : null,
                    'subtitle' => $isCover ? ($page['subtitle'] ?? null) : null,
                    'blocks' => $isCover ? [] : array_values($page['blocks']),
                    'signature' => $isFinal ? $signature : null,
                    'truncated' => $isFinal && $truncatedPage !== null,
                    'continuation_label' => $isFinal
                        ? ($truncatedPage['continuation_label'] ?? null)
                        : null,
                ];
            },
            $pages,
            array_keys($pages),
        );
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
