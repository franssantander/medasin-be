<?php

namespace App\Jobs\Letter;

use App\Enum\LetterExportStatus;
use App\Enum\LetterStatus;
use App\Models\Letter;
use App\Models\LetterExport;
use App\Services\Letter\LetterPaginationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GenerateLetterExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    public int $timeout = 60;

    public function __construct(public LetterExport $letterExport) {}

    public function handle(
        LetterPaginationService $pagination,
    ): void {
        $export = LetterExport::query()
            ->with('letter.user')
            ->find($this->letterExport->getKey());
        if (! $export) {
            return;
        }

        $export->forceFill([
            'status' => LetterExportStatus::PROCESSING,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        $letter = $export->letter;
        if (! $letter) {
            throw new RuntimeException('The letter for this export is unavailable.');
        }

        $pages = $pagination->paginate($letter, $export->format);

        DB::transaction(function () use ($export, $letter, $pages): void {
            $currentExport = LetterExport::query()->lockForUpdate()->find($export->getKey());
            $currentLetter = Letter::query()->lockForUpdate()->find($letter->getKey());
            if (! $currentExport || ! $currentLetter) {
                return;
            }

            $currentExport->forceFill([
                'status' => LetterExportStatus::READY,
                'pages' => $pages,
                'page_count' => count($pages),
                'completed_at' => now(),
                'error_message' => null,
            ])->save();

            if ($currentLetter->sourceHash() === $currentExport->source_hash) {
                $currentLetter->forceFill([
                    'status' => LetterStatus::EXPORTED,
                    'exported_at' => now(),
                ])->save();
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $export = LetterExport::query()->find($this->letterExport->getKey());
        if (! $export) {
            return;
        }

        $export->forceFill([
            'status' => LetterExportStatus::FAILED,
            'error_message' => 'The letter export could not be prepared.',
        ])->save();
    }
}
