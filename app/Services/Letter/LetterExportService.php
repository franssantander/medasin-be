<?php

namespace App\Services\Letter;

use App\Enum\LetterExportFormat;
use App\Enum\LetterExportStatus;
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
    ) {}

    public function create(Letter $letter, LetterExportFormat $format): LetterExport
    {
        $profile = $this->pagination->profile($format);

        $export = DB::transaction(function () use ($letter, $format, $profile): LetterExport {
            $export = $letter->exports()->create([
                'format' => $format,
                'canvas_width' => $profile['width'],
                'canvas_height' => $profile['height'],
                'source_hash' => $letter->sourceHash(),
                'status' => LetterExportStatus::QUEUED,
            ]);

            GenerateLetterExport::dispatch($export)->afterCommit();

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
        $lastIndex = array_key_last($pages);

        $normalizedPages = array_map(
            function (array $page, int $index) use ($lastIndex, $signature, $truncatedPage): array {
                $isCover = $index === 0;
                $isFinal = $index === $lastIndex;

                return [
                    'uuid' => $page['uuid'],
                    'number' => $index + 1,
                    'kind' => $isCover ? 'cover' : ($isFinal ? 'final' : 'body'),
                    'layout' => $page['layout'],
                    'text_scale' => (float) ($page['text_scale'] ?? 1),
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

        $export->forceFill([
            'pages' => $normalizedPages,
            'page_count' => count($normalizedPages),
        ])->save();

        return $this->find($letter, $export);
    }
}
