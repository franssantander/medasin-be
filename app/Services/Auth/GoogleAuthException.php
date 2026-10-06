<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GoogleAuthException extends HttpException implements ShouldntReport
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $status = 400,
    ) {
        parent::__construct($status, $message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'status' => $this->getStatusCode(),
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->getStatusCode());
    }

    public static function unavailable(): self
    {
        return new self('GOOGLE_AUTH_UNAVAILABLE', 'Google sign-in is temporarily unavailable. Please try again.', 503);
    }
}
