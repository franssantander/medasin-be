<?php

namespace App\Http\Controllers\Area;

use App\Data\Note\NoteData;
use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Note\StoreNoteMediaRequest;
use App\Http\Requests\Note\StoreNoteRequest;
use App\Http\Requests\Note\UpdateNoteRequest;
use App\Models\Area;
use App\Models\Note;
use App\Services\Note\NoteService;
use App\Services\Trash\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AreaNoteController extends Controller
{
    use InteractsWithOwnedAreas;

    public function __construct(
        private readonly NoteService $noteService,
        private readonly TrashService $trashService,
    ) {}

    public function index(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->cached($request, fn (): JsonResponse => $this->success(
            $area->notes()->orderByDesc('is_pinned')->latest('updated_at')->paginate(15),
        ));
    }

    public function store(StoreNoteRequest $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $note = $this->noteService->create(
            $area->notes(),
            NoteData::from($request->validated()),
            'The selected parent note does not belong to this area.',
        );

        return $this->success($note, 'Successfully created note.', 201);
    }

    public function show(Request $request, Area $area, Note $note)
    {
        $area = $this->ownedArea($request->user(), $area);
        $note = $area->notes()->whereKey($note->getKey())->firstOrFail();

        return $this->cached($request, fn (): JsonResponse => $this->success($note));
    }

    public function update(UpdateNoteRequest $request, Area $area, Note $note)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $note = $area->notes()->whereKey($note->getKey())->firstOrFail();
        $note = $this->noteService->update(
            $area->notes(),
            $note,
            NoteData::from($request->validated()),
            'The selected parent note does not belong to this area.',
        );

        return $this->success($note, 'Successfully updated note.');
    }

    public function destroy(Request $request, Area $area, Note $note)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $note = $area->notes()->whereKey($note->getKey())->firstOrFail();
        $this->trashService->deleteNoteTree($request->user(), $note, $area->name);

        return $this->success(null, 'Note and its subpages moved to Trash. They will be permanently deleted after 30 days.');
    }

    public function tree(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->cached($request, fn (): JsonResponse => $this->success($this->noteService->tree($area->notes())));
    }

    public function storeMedia(StoreNoteMediaRequest $request, Area $area, Note $note)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $note = $area->notes()->whereKey($note->getKey())->firstOrFail();

        return $this->success(
            $this->noteService->storeMedia($request->user(), $note, $request->file('file'), $area),
            'Successfully uploaded note media.',
            201,
        );
    }
}
