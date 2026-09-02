<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Exception;

/**
 * Raised for any CryptoPay API response with an HTTP status >= 400.
 *
 * getMessage() returns the API-provided message. getErrorCode() returns the
 * machine-readable `error.code` from the response envelope (e.g.
 * `validation_error`, `not_found`, `unauthenticated`, `invalid_state`,
 * `rate_limited`, `server_error`), or `http_error` when the body could not
 * be parsed and the status was not >= 500.
 */
class ApiException extends CryptoPayException
{
    /**
     * @param  array<mixed>  $details
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus,
        private readonly array $details = [],
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function isValidationError(): bool
    {
        return $this->errorCode === 'validation_error';
    }

    public function isNotFound(): bool
    {
        return $this->errorCode === 'not_found';
    }

    public function isRateLimited(): bool
    {
        return $this->errorCode === 'rate_limited';
    }
}
