<?php

namespace App\Http\Controllers\Journal;

use App\Data\Journal\JournalEntryData;
use App\Data\Journal\JournalEntryResponseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\ListJournalEntryRequest;
use App\Http\Requests\Journal\StoreJournalEntryRequest;
use App\Http\Requests\Journal\UpdateJournalEntryRequest;
use App\Models\JournalEntry;
use App\Services\Journal\JournalService;
use App\Services\Trash\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function __construct(
        private readonly JournalService $journalService,
        private readonly TrashService $trashService,
    ) {}

    public function index(ListJournalEntryRequest $request): JsonResponse
    {
        $entries = $this->journalService->listing(
            $request->user(),
            $request->validated('per_page', 15),
        );
        $entries->through(fn (JournalEntry $entry): array => JournalEntryResponseData::fromModel($entry)->toArray());

        return $this->success($entries);
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $entry = $this->journalService->create(
            $request->user(),
            JournalEntryData::from($request->validated()),
        );

        return $this->success(
            JournalEntryResponseData::fromModel($entry, includeContent: true)->toArray(),
            'Successfully created journal entry.',
            201,
        );
    }

    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->journalService->find($request->user(), $journalEntry);

        return $this->success(
            JournalEntryResponseData::fromModel($entry, includeContent: true)->toArray(),
        );
    }

    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->journalService->find($request->user(), $journalEntry);
        $entry = $this->journalService->update(
            $request->user(),
            $entry,
            JournalEntryData::from($request->validated()),
        );

        return $this->success(
            JournalEntryResponseData::fromModel($entry, includeContent: true)->toArray(),
            'Successfully updated journal entry.',
        );
    }

    public function destroy(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->journalService->find($request->user(), $journalEntry);
        $this->trashService->delete(
            $request->user(),
            $entry,
            'journal_entry',
            $entry->title,
            'Journal',
        );

        return $this->success(
            null,
            'Journal entry moved to Trash. It will be permanently deleted after 30 days.',
        );
    }
}
