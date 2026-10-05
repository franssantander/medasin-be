<?php

namespace App\Http\Controllers;

use App\Services\ApiReadCacheService;
use App\Support\ApiResponder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

abstract class Controller
{
    /**
     * @param  Closure(): JsonResponse  $resolve
     * @param  array<string, mixed>  $context
     */
    protected function cached(Request $request, Closure $resolve, array $context = []): JsonResponse
    {
        return app(ApiReadCacheService::class)->response($request, $resolve, $context);
    }

    protected function success(
        mixed $data = null,
        string $message = 'Request was successful.',
        int $status = 200
    ): JsonResponse {
        return ApiResponder::success($data, $message, $status);
    }

    protected function error(
        ?Throwable $exception = null,
        ?string $message = null,
        int $status = 500
    ): JsonResponse {
        return ApiResponder::error($exception, $message, $status);
    }
}
