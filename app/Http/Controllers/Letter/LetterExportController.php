<?php

namespace App\Http\Controllers\Letter;

use App\Enum\LetterExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Letter\ListLetterExportRequest;
use App\Http\Requests\Letter\StoreLetterExportRequest;
use App\Http\Resources\Letter\LetterExportResource;
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
        $exports->through(fn (LetterExport $export): array => LetterExportResource::make($export)->resolve($request));

        return $this->success($exports);
    }

    public function store(StoreLetterExportRequest $request, Letter $letter): JsonResponse
    {
        $letter = $this->letterService->find($request->user(), $letter);
        $format = LetterExportFormat::from($request->validated('format', LetterExportFormat::PORTRAIT->value));
        $export = $this->exportService->create($letter, $format);

        return $this->success(
            LetterExportResource::make($export)->resolve($request),
            'Letter export queued.',
            202,
        );
    }

    public function show(Request $request, Letter $letter, LetterExport $letterExport): JsonResponse
    {
        $letter = $this->letterService->find($request->user(), $letter);
        $letterExport = $this->exportService->find($letter, $letterExport);

        return $this->success(LetterExportResource::make($letterExport)->resolve($request));
    }
}
