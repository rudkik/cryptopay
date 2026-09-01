<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

final class ErrorResponse
{
    public static function make(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status);
    }
}
