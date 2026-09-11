<?php

namespace App\Services\Letter;

use App\Enum\LetterExportFormat;
use App\Enum\LetterExportStatus;
use App\Jobs\Letter\GenerateLetterExport;
use App\Models\Letter;
use App\Models\LetterExport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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
}
