<?php

namespace App\Http\Controllers\Journal;

use App\Data\Journal\JournalEntryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\ListJournalEntryRequest;
use App\Http\Requests\Journal\StoreJournalEntryRequest;
use App\Http\Requests\Journal\UpdateJournalEntryRequest;
use App\Http\Resources\Journal\JournalEntryResource;
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
        $entries->through(fn (JournalEntry $entry): array => JournalEntryResource::make($entry)->resolve($request));

        return $this->success($entries);
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $entry = $this->journalService->create(
            $request->user(),
            JournalEntryData::from($request->validated()),
        );

        return $this->success(
            JournalEntryResource::make($entry)->withContent()->resolve($request),
            'Successfully created journal entry.',
            201,
        );
    }

    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->journalService->find($request->user(), $journalEntry);

        return $this->success(
            JournalEntryResource::make($entry)->withContent()->resolve($request),
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
            JournalEntryResource::make($entry)->withContent()->resolve($request),
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
