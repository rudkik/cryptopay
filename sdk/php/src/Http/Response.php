<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Http;

/**
 * An HTTP response as seen by the SDK's transport layer.
 */
final readonly class Response
{
    /**
     * @param  array<string, string>  $headers  Lower-cased header names => value.
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    /**
     * Decode the body as JSON.
     *
     * @return array<mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        if (! is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public function isJson(): bool
    {
        if ($this->body === '') {
            return false;
        }

        json_decode($this->body);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
