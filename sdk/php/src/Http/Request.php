<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Http;

/**
 * A record of an outgoing HTTP request. Not required by TransportInterface,
 * but handy for fakes/tests to assert against.
 */
final readonly class Request
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
    ) {
    }
}
