<?php

namespace App\Http\Controllers\Letter;

use App\Data\Letter\LetterData;
use App\Enum\LetterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Letter\ListLetterRequest;
use App\Http\Requests\Letter\StoreLetterRequest;
use App\Http\Requests\Letter\UpdateLetterRequest;
use App\Http\Resources\Letter\LetterResource;
use App\Models\Letter;
use App\Services\Letter\LetterService;
use App\Services\Trash\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LetterController extends Controller
{
    public function __construct(
        private readonly LetterService $letterService,
        private readonly TrashService $trashService,
    ) {}

    public function index(ListLetterRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $status = isset($validated['status']) ? LetterStatus::from($validated['status']) : null;
        $letters = $this->letterService->listing(
            $request->user(),
            $status,
            $validated['per_page'] ?? 15,
        );
        $letters->through(fn (Letter $letter): array => LetterResource::make($letter)->resolve($request));

        return $this->success($letters);
    }

    public function store(StoreLetterRequest $request): JsonResponse
    {
        $letter = $this->letterService->create(
            $request->user(),
            LetterData::from($request->validated()),
        );

        return $this->success(
            LetterResource::make($letter)->withContent()->resolve($request),
            'Successfully created letter.',
            201,
        );
    }

    public function show(Request $request, Letter $letter): JsonResponse
    {
        $letter = $this->letterService->find($request->user(), $letter);

        return $this->success(
            LetterResource::make($letter)->withContent()->resolve($request),
        );
    }

    public function update(UpdateLetterRequest $request, Letter $letter): JsonResponse
    {
        $letter = $this->letterService->find($request->user(), $letter);
        $letter = $this->letterService->update(
            $request->user(),
            $letter,
            LetterData::from($request->validated()),
        );

        return $this->success(
            LetterResource::make($letter)->withContent()->resolve($request),
            'Successfully updated letter.',
        );
    }

    public function destroy(Request $request, Letter $letter): JsonResponse
    {
        $letter = $this->letterService->find($request->user(), $letter);
        $this->trashService->delete(
            $request->user(),
            $letter,
            'letter',
            $letter->title,
            'Letters',
        );

        return $this->success(
            null,
            'Letter moved to Trash. It will be permanently deleted after 30 days.',
        );
    }
}
