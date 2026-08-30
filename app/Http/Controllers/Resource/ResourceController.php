<?php

namespace App\Http\Controllers\Resource;

use App\Data\Resource\ResourceData;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $resources = $request->user()
            ->resources()
            ->whereNull('archived_at')
            ->latest()
            ->get();

        $data = $resources
            ->map(fn ($resource) => ResourceData::fromModel($resource)->toArray())
            ->all();

        return $this->success($data);
    }
}
