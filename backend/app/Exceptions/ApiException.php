<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base for every domain error that must surface as the SPEC §6 error envelope.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        protected string $errorCode,
        string $message,
        protected int $status = 400,
        protected array $details = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->details;
    }

    public function render(): JsonResponse
    {
        return ErrorResponse::make($this->errorCode, $this->getMessage(), $this->status, $this->details);
    }
}
