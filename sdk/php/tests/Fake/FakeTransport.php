<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Tests\Fake;

use CryptoPay\Sdk\Exception\TransportException;
use CryptoPay\Sdk\Http\Request;
use CryptoPay\Sdk\Http\Response;
use CryptoPay\Sdk\Http\TransportInterface;

/**
 * A TransportInterface that returns a queue of canned responses (or defers
 * to a callable) and records every request sent through it, for asserting
 * against in tests.
 */
final class FakeTransport implements TransportInterface
{
    /** @var Response[] */
    private array $queue = [];

    /** @var (callable(string, string, array<string, string>, ?string): Response)|null */
    private $handler = null;

    /** @var Request[] */
    public array $requests = [];

    public function queue(Response $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * @param  callable(string, string, array<string, string>, ?string): Response  $handler
     */
    public function handleWith(callable $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->requests[] = new Request($method, $url, $headers, $body);

        if ($this->handler !== null) {
            return ($this->handler)($method, $url, $headers, $body);
        }

        if ($this->queue === []) {
            throw new TransportException('FakeTransport: no queued response for '.$method.' '.$url);
        }

        return array_shift($this->queue);
    }

    public function lastRequest(): ?Request
    {
        return $this->requests[count($this->requests) - 1] ?? null;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
