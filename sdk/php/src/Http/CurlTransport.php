<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Http;

use CryptoPay\Sdk\Exception\TransportException;

/**
 * Plain ext-curl transport. No framework/HTTP-client dependency.
 */
final class CurlTransport implements TransportInterface
{
    /** Hard ceiling on a response body, in bytes. The API returns small JSON. */
    public const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        private readonly int $maxResponseBytes = self::MAX_RESPONSE_BYTES,
    ) {
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new TransportException('Unable to initialise cURL.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        $responseHeaders = [];
        $buffer = '';
        $overflowed = false;

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,

            // TLS verification is on by default, but state it explicitly so the
            // guarantee survives an odd libcurl build or a copied-and-edited
            // transport. There is deliberately no option to turn it off.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            // The merchant API never redirects. Following one would forward the
            // Authorization header on a route we did not choose, so a 3xx is
            // surfaced to the caller as-is instead.
            CURLOPT_FOLLOWLOCATION => false,

            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $responseHeaders[$name] = $value;
                }

                return $length;
            },

            // Bounded read: a hostile or broken endpoint cannot stream the
            // process out of memory. Returning a short count aborts the transfer.
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$buffer, &$overflowed): int {
                if (strlen($buffer) + strlen($chunk) > $this->maxResponseBytes) {
                    $overflowed = true;

                    return 0;
                }

                $buffer .= $chunk;

                return strlen($chunk);
            },
        ]);

        // Restrict the schemes curl will speak, so a mistyped base URL cannot
        // turn an API call into a file:// or gopher:// read.
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'http,https');
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($handle);

        if ($result === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);

            if ($overflowed) {
                throw new TransportException(
                    sprintf('CryptoPay response exceeded %d bytes and was discarded.', $this->maxResponseBytes)
                );
            }

            throw new TransportException(
                sprintf('cURL error (%d): %s', $errno, $error !== '' ? $error : 'unknown transport error')
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new Response($status, $responseHeaders, $buffer);
    }
}
