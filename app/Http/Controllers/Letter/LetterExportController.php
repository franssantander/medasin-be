<?php

namespace App\Http\Controllers\Letter;

use App\Data\Letter\LetterExportData;
use App\Data\Letter\LetterResponseData;
use App\Enum\LetterExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Letter\ListLetterExportRequest;
use App\Http\Requests\Letter\StoreLetterExportRequest;
use App\Http\Requests\Letter\UpdateLetterExportRequest;
use App\Models\Letter;
use App\Models\LetterExport;
use App\Services\Letter\LetterExportService;
use App\Services\Letter\LetterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LetterExportController extends Controller
{
    public function __construct(
        private readonly LetterService $letterService,
        private readonly LetterExportService $exportService,
    ) {}

    public function index(ListLetterExportRequest $request, Letter $letter): JsonResponse
    {
        $letter = $this->letterService->find($request->user(), $letter);
        $exports = $this->exportService->listing($letter, $request->validated('per_page', 15));
        $exports->through(fn (LetterExport $export): array => LetterExportData::fromModel($export)->toArray());

        return $this->success($exports);
    }

    public function store(StoreLetterExportRequest $request, Letter $letter): JsonResponse
    {
        $letter = $this->letterService->find($request->user(), $letter);
        $format = LetterExportFormat::from($request->validated('format', LetterExportFormat::PORTRAIT->value));
        $pages = $request->validated('pages');
        $export = $this->exportService->create($letter, $format, $pages);

        return $this->success(
            LetterExportData::fromModel($export)->toArray(),
            $pages === null ? 'Letter export queued.' : 'Letter pages prepared.',
            $pages === null ? 202 : 201,
        );
    }

    public function show(Request $request, Letter $letter, LetterExport $letterExport): JsonResponse
    {
        $letter = $this->letterService->find($request->user(), $letter);
        $letterExport = $this->exportService->find($letter, $letterExport);

        return $this->success(LetterExportData::fromModel($letterExport)->toArray());
    }

    public function update(UpdateLetterExportRequest $request, Letter $letter, LetterExport $letterExport): JsonResponse
    {
        $letter = $this->letterService->find($request->user(), $letter);
        $letterExport = $this->exportService->updatePages(
            $letter,
            $letterExport,
            $request->validated('pages'),
        );
        $letter = $this->letterService->find($request->user(), $letter);

        return $this->success(
            [
                'export' => LetterExportData::fromModel($letterExport)->toArray(),
                'letter' => LetterResponseData::fromModel($letter, includeContent: true)->toArray(),
            ],
            'Letter pages saved.',
        );
    }
}
