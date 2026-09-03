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

    /** The only signature shape the backend ever emits: sha256= + 64 hex chars. */
    private const SIGNATURE_PATTERN = '/^sha256=[0-9a-f]{64}$/';

    /** A unix timestamp as an unsigned decimal integer, nothing else. */
    private const TIMESTAMP_PATTERN = '/^[0-9]{1,19}$/';

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
        // An empty secret is never a valid configuration: HMAC with an empty key
        // is perfectly computable by anyone, so accepting it would turn a missing
        // CRYPTOPAY_WEBHOOK_SECRET into "every forged delivery verifies".
        if ($secret === '') {
            throw new SignatureException('CryptoPay webhook secret is not configured.');
        }

        $signature = self::header($headers, self::HEADER_SIGNATURE);
        if ($signature === null || $signature === '') {
            throw new SignatureException('Missing '.self::HEADER_SIGNATURE.' header.');
        }

        // Hex is case-insensitive on the wire; the comparison below is not, so
        // normalise before both the shape check and hash_equals().
        $signature = strtolower($signature);
        if (preg_match(self::SIGNATURE_PATTERN, $signature) !== 1) {
            throw new SignatureException('Malformed '.self::HEADER_SIGNATURE.' header.');
        }

        $timestampHeader = self::header($headers, self::HEADER_TIMESTAMP);
        if ($timestampHeader === null || preg_match(self::TIMESTAMP_PATTERN, $timestampHeader) !== 1) {
            throw new SignatureException('Missing or malformed '.self::HEADER_TIMESTAMP.' header.');
        }

        $timestamp = (int) $timestampHeader;

        // abs() enforces the window in BOTH directions: a replayed stale delivery
        // and a far-future timestamp are equally rejected.
        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            throw new SignatureException('Webhook timestamp is outside the allowed tolerance.');
        }

        $expected = self::sign($secret, $timestamp, $rawBody);

        // Constant-time comparison; the expected value goes first so the
        // attacker-controlled string is the one being probed.
        if (! hash_equals($expected, $signature)) {
            throw new SignatureException('Webhook signature mismatch.');
        }

        // Only now is the body trusted enough to parse.

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
