<?php

namespace App\Http\Controllers\Area;

use App\Data\Area\NoteData;
use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Area\StoreNoteMediaRequest;
use App\Http\Requests\Area\StoreNoteRequest;
use App\Http\Requests\Area\UpdateNoteRequest;
use App\Models\Area;
use App\Models\Note;
use App\Services\Area\NoteService;
use App\Services\Trash\TrashService;
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
        $notes = $area->notes()->orderByDesc('is_pinned')->latest('updated_at')->paginate(15);

        return $this->success($notes);
    }

    public function store(StoreNoteRequest $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $note = $this->noteService->create($area, NoteData::from($request->validated()));

        return $this->success($note, 'Successfully created note.', 201);
    }

    public function show(Request $request, Area $area, Note $note)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->success($area->notes()->whereKey($note->getKey())->firstOrFail());
    }

    public function update(UpdateNoteRequest $request, Area $area, Note $note)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $note = $area->notes()->whereKey($note->getKey())->firstOrFail();
        $note = $this->noteService->update($area, $note, NoteData::from($request->validated()));

        return $this->success($note, 'Successfully updated note.');
    }

    public function destroy(Request $request, Area $area, Note $note)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $note = $area->notes()->whereKey($note->getKey())->firstOrFail();
        $this->trashService->deleteNoteTree($request->user(), $area, $note);

        return $this->success(null, 'Note and its subpages moved to Trash. They will be permanently deleted after 30 days.');
    }

    public function tree(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->success($this->noteService->tree($area));
    }

    public function storeMedia(StoreNoteMediaRequest $request, Area $area, Note $note)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $note = $area->notes()->whereKey($note->getKey())->firstOrFail();

        return $this->success(
            $this->noteService->storeMedia($area, $note, $request->file('file')),
            'Successfully uploaded note media.',
            201,
        );
    }
}
