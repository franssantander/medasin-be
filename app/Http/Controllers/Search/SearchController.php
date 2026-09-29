<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, SearchService $search): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($search->search(
            $request->user(),
            $validated['q'],
            $validated['type'] ?? null,
            (int) ($validated['limit'] ?? 5),
            (bool) ($validated['include_archived'] ?? false),
        ), 'OK');
    }
}
