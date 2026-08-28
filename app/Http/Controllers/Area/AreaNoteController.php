<?php

namespace App\Http\Controllers\Area;

use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Area\StoreNoteRequest;
use App\Http\Requests\Area\UpdateNoteRequest;
use App\Models\Area;
use App\Models\Note;
use Illuminate\Http\Request;

class AreaNoteController extends Controller
{
    use InteractsWithOwnedAreas;

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
        $note = $area->notes()->create($request->validated());

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
        $note->update($request->validated());

        return $this->success($note->fresh(), 'Successfully updated note.');
    }

    public function destroy(Request $request, Area $area, Note $note)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $area->notes()->whereKey($note->getKey())->firstOrFail()->delete();

        return $this->success(null, 'Successfully deleted note.');
    }
}
