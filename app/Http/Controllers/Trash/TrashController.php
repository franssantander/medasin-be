<?php

namespace App\Http\Controllers\Trash;

use App\Http\Controllers\Controller;
use App\Models\TrashEntry;
use App\Services\Trash\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrashController extends Controller
{
    public function __construct(private readonly TrashService $trashService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'type' => ['sometimes', 'nullable', Rule::in(TrashService::TYPES)],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        $query = $request->user()->trashEntries()->where('expires_at', '>', now());
        if ($search = trim($validated['search'] ?? '')) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
            $query->where(fn ($builder) => $builder
                ->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                ->orWhereRaw("LOWER(context) LIKE ? ESCAPE '!'", ["%{$escaped}%"]));
        }
        if ($type = $validated['type'] ?? null) {
            $query->where('item_type', $type);
        }

        $page = $query->latest('deleted_at')->paginate($validated['per_page'] ?? 15);
        $page->through(fn (TrashEntry $entry) => $this->trashService->serialize($entry));

        return $this->success($page);
    }

    public function restore(Request $request, TrashEntry $trashEntry): JsonResponse
    {
        $entry = $request->user()->trashEntries()->whereKey($trashEntry->getKey())->firstOrFail();
        if ($entry->expires_at->lessThanOrEqualTo(now())) {
            $this->trashService->forceDelete($entry);
            abort(410, 'This item has expired and was permanently deleted.');
        }
        $title = $entry->title;
        $this->trashService->restore($entry);

        return $this->success(null, "Successfully restored {$title}.");
    }

    public function destroy(Request $request, TrashEntry $trashEntry): JsonResponse
    {
        $entry = $request->user()->trashEntries()->whereKey($trashEntry->getKey())->firstOrFail();
        $title = $entry->title;
        $this->trashService->forceDelete($entry);

        return $this->success(null, "Permanently deleted {$title}.");
    }
}
