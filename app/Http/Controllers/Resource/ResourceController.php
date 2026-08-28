<?php

namespace App\Http\Controllers\Resource;

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

        return $this->success($resources);
    }
}
