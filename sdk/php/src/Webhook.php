<?php

declare(strict_types=1);

namespace CryptoPay\Sdk;

use CryptoPay\Sdk\Exception\SignatureException;

/**
 * Verifies and signs CryptoPay webhook deliveries.
 *
 * Signed string: "{timestamp}.{rawBody}", HMAC-SHA256 with the merchant's
 * webhook secret, hex-encoded and prefixed with "sha256=". Matches the
 * backend's `WebhookService::sign()`.
 */
final class Webhook
{
    private const HEADER_SIGNATURE = 'X-CryptoPay-Signature';

    private const HEADER_TIMESTAMP = 'X-CryptoPay-Timestamp';

    private const HEADER_EVENT = 'X-CryptoPay-Event';

    private const HEADER_DELIVERY = 'X-CryptoPay-Delivery';

    /**
     * Verify a raw webhook payload and return the decoded event.
     *
     * @param  array<string, mixed>  $headers  Any mix of PSR-7-style ("X-CryptoPay-Signature"),
     *                                          case-varied, or PHP $_SERVER-style ("HTTP_X_CRYPTOPAY_SIGNATURE")
     *                                          header keys. Array values (as from getallheaders()
     *                                          duplicates) are reduced to their first element.
     *
     * @throws SignatureException
     */
    public static function verify(string $rawBody, array $headers, string $secret, int $tolerance = 300): WebhookEvent
    {
        $signature = self::header($headers, self::HEADER_SIGNATURE);
        if ($signature === null || $signature === '') {
            throw new SignatureException('Missing '.self::HEADER_SIGNATURE.' header.');
        }

        $timestampHeader = self::header($headers, self::HEADER_TIMESTAMP);
        if ($timestampHeader === null || $timestampHeader === '' || ! is_numeric($timestampHeader)) {
            throw new SignatureException('Missing or non-numeric '.self::HEADER_TIMESTAMP.' header.');
        }

        $timestamp = (int) $timestampHeader;

        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            throw new SignatureException('Webhook timestamp is outside the allowed tolerance.');
        }

        $expected = self::sign($secret, $timestamp, $rawBody);

        if (! hash_equals($expected, $signature)) {
            throw new SignatureException('Webhook signature mismatch.');
        }

        $payload = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($payload)) {
            throw new SignatureException('Webhook body is not valid JSON.');
        }

        $eventHeader = self::header($headers, self::HEADER_EVENT);
        $deliveryHeader = self::header($headers, self::HEADER_DELIVERY);

        return WebhookEvent::fromArray($payload, $eventHeader, $deliveryHeader);
    }

    /**
     * Compute the signature for a raw body at a given timestamp.
     */
    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * Case-insensitive header lookup that also understands PHP's
     * $_SERVER-style HTTP_* keys and array (multi-value) headers.
     *
     * @param  array<string, mixed>  $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $normalized = strtolower(str_replace('-', '_', $name));

        foreach ($headers as $key => $value) {
            $candidate = strtolower(str_replace('-', '_', (string) $key));
            $candidate = str_starts_with($candidate, 'http_') ? substr($candidate, 5) : $candidate;

            if ($candidate === $normalized) {
                if (is_array($value)) {
                    $value = $value[0] ?? null;
                }

                return $value === null ? null : (string) $value;
            }
        }

        return null;
    }
}
