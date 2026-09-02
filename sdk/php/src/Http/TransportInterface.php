<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Http;

use CryptoPay\Sdk\Exception\TransportException;

interface TransportInterface
{
    /**
     * Send a single HTTP request and return the raw response.
     *
     * @param  array<string, string>  $headers
     *
     * @throws TransportException on a network/transport-level failure.
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response;
}
