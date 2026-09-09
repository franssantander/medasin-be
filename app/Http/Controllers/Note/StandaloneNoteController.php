<?php

namespace App\Http\Controllers\Note;

use App\Data\Note\NoteData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Note\StoreNoteMediaRequest;
use App\Http\Requests\Note\StoreNoteRequest;
use App\Http\Requests\Note\UpdateNoteRequest;
use App\Models\Note;
use App\Models\User;
use App\Services\Note\NoteService;
use App\Services\Trash\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StandaloneNoteController extends Controller
{
    public function __construct(
        private readonly NoteService $noteService,
        private readonly TrashService $trashService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $notes = $request->user()->standaloneNotes()
            ->orderByDesc('is_pinned')
            ->latest('updated_at')
            ->paginate(15);

        return $this->success($notes);
    }

    public function store(StoreNoteRequest $request): JsonResponse
    {
        $note = $this->noteService->create(
            $request->user()->standaloneNotes(),
            NoteData::from($request->validated()),
        );

        return $this->success($note, 'Successfully created note.', 201);
    }

    public function show(Request $request, Note $note): JsonResponse
    {
        return $this->success($this->standaloneNote($request->user(), $note));
    }

    public function update(UpdateNoteRequest $request, Note $note): JsonResponse
    {
        $note = $this->standaloneNote($request->user(), $note);
        $note = $this->noteService->update(
            $request->user()->standaloneNotes(),
            $note,
            NoteData::from($request->validated()),
        );

        return $this->success($note, 'Successfully updated note.');
    }

    public function destroy(Request $request, Note $note): JsonResponse
    {
        $note = $this->standaloneNote($request->user(), $note);
        $this->trashService->deleteNoteTree($request->user(), $note, 'Notes');

        return $this->success(null, 'Note and its subpages moved to Trash. They will be permanently deleted after 30 days.');
    }

    public function tree(Request $request): JsonResponse
    {
        return $this->success($this->noteService->tree($request->user()->standaloneNotes()));
    }

    public function storeMedia(StoreNoteMediaRequest $request, Note $note): JsonResponse
    {
        $note = $this->standaloneNote($request->user(), $note);

        return $this->success(
            $this->noteService->storeMedia(
                $note,
                "notes/{$request->user()->uuid}/{$note->uuid}",
                $request->file('file'),
            ),
            'Successfully uploaded note media.',
            201,
        );
    }

    private function standaloneNote(User $user, Note $note): Note
    {
        return $user->standaloneNotes()->whereKey($note->getKey())->firstOrFail();
    }
}
