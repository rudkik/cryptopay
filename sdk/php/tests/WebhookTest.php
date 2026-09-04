<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Tests;

use CryptoPay\Sdk\Exception\SignatureException;
use CryptoPay\Sdk\Webhook;
use CryptoPay\Sdk\WebhookEvent;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret_value';

    /**
     * @return array<mixed>
     */
    private function payload(): array
    {
        return [
            'id' => 'delivery-uuid-1',
            'event' => 'invoice.paid',
            'created_at' => '2026-09-02T05:20:00+00:00',
            'data' => [
                'invoice' => [
                    'id' => '01a06089-c3c9-7394-9750-480c130315fd',
                    'type' => 'payment',
                    'external_id' => 'probe-1',
                    'status' => 'paid',
                    'is_paid' => true,
                    'currency' => 'USDT',
                    'network' => 'tron',
                    'amount' => '12.500000',
                    'amount_received' => '12.500000',
                    'amount_confirmed' => '12.500000',
                    'address' => 'TAofMk3exampleaddress',
                    'payment_url' => 'http://localhost:8095/pay/01a06089-c3c9-7394-9750-480c130315fd',
                    'qr_payload' => 'TAofMk3exampleaddress',
                    'description' => null,
                    'customer_email' => null,
                    'customer_id' => null,
                    'metadata' => [],
                    'success_url' => null,
                    'cancel_url' => null,
                    'expires_at' => '2026-09-02T06:13:56+00:00',
                    'paid_at' => '2026-09-02T05:20:00+00:00',
                    'created_at' => '2026-09-02T05:13:56+00:00',
                    'transactions' => [],
                    'token_purchase' => null,
                ],
                'token_purchase' => null,
            ],
        ];
    }

    /**
     * @return array{body: string, headers: array<string, string>, timestamp: int}
     */
    private function signedDelivery(?string $secret = null, ?int $timestamp = null): array
    {
        $body = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature = Webhook::sign($secret ?? self::SECRET, $timestamp, $body);

        return [
            'body' => $body,
            'timestamp' => $timestamp,
            'headers' => [
                'X-CryptoPay-Event' => 'invoice.paid',
                'X-CryptoPay-Delivery' => 'delivery-uuid-1',
                'X-CryptoPay-Timestamp' => (string) $timestamp,
                'X-CryptoPay-Signature' => $signature,
            ],
        ];
    }

    public function test_sign_matches_backend_formula(): void
    {
        $signature = Webhook::sign('my-secret', 1700000000, '{"a":1}');

        self::assertSame(
            'sha256='.hash_hmac('sha256', '1700000000.{"a":1}', 'my-secret'),
            $signature
        );
    }

    public function test_valid_signature_produces_populated_webhook_event(): void
    {
        $delivery = $this->signedDelivery();

        $event = Webhook::verify($delivery['body'], $delivery['headers'], self::SECRET);

        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertSame('invoice.paid', $event->event);
        self::assertSame('delivery-uuid-1', $event->deliveryId);
        self::assertSame('delivery-uuid-1', $event->id);
        self::assertNotNull($event->invoice);
        self::assertTrue($event->invoice->isPaid());
        self::assertTrue($event->isPaid());
        self::assertTrue($event->isInvoiceEvent());
        self::assertNull($event->tokenPurchase);
        self::assertSame(json_decode($delivery['body'], true), $event->toArray());
    }

    public function test_case_insensitive_and_server_style_headers_are_accepted(): void
    {
        $delivery = $this->signedDelivery();

        $serverStyleHeaders = [
            'HTTP_X_CRYPTOPAY_EVENT' => 'invoice.paid',
            'HTTP_X_CRYPTOPAY_DELIVERY' => 'delivery-uuid-1',
            'HTTP_X_CRYPTOPAY_TIMESTAMP' => $delivery['headers']['X-CryptoPay-Timestamp'],
            'HTTP_X_CRYPTOPAY_SIGNATURE' => $delivery['headers']['X-CryptoPay-Signature'],
        ];

        $event = Webhook::verify($delivery['body'], $serverStyleHeaders, self::SECRET);

        self::assertSame('invoice.paid', $event->event);
    }

    public function test_array_value_headers_take_the_first_element(): void
    {
        $delivery = $this->signedDelivery();

        $headers = [
            'x-cryptopay-event' => ['invoice.paid'],
            'x-cryptopay-delivery' => ['delivery-uuid-1'],
            'x-cryptopay-timestamp' => [$delivery['headers']['X-CryptoPay-Timestamp']],
            'x-cryptopay-signature' => [$delivery['headers']['X-CryptoPay-Signature']],
        ];

        $event = Webhook::verify($delivery['body'], $headers, self::SECRET);

        self::assertSame('invoice.paid', $event->event);
    }

    public function test_tampered_body_fails_verification(): void
    {
        $delivery = $this->signedDelivery();
        $tamperedBody = str_replace('"invoice.paid"', '"invoice.cancelled"', $delivery['body']);

        $this->expectException(SignatureException::class);
        Webhook::verify($tamperedBody, $delivery['headers'], self::SECRET);
    }

    public function test_wrong_secret_fails_verification(): void
    {
        $delivery = $this->signedDelivery();

        $this->expectException(SignatureException::class);
        Webhook::verify($delivery['body'], $delivery['headers'], 'a-completely-different-secret');
    }

    public function test_stale_timestamp_fails_verification(): void
    {
        $delivery = $this->signedDelivery(timestamp: time() - 1000);

        $this->expectException(SignatureException::class);
        Webhook::verify($delivery['body'], $delivery['headers'], self::SECRET, 300);
    }

    public function test_stale_timestamp_passes_when_tolerance_is_zero(): void
    {
        $delivery = $this->signedDelivery(timestamp: time() - 1000);

        $event = Webhook::verify($delivery['body'], $delivery['headers'], self::SECRET, 0);

        self::assertSame('invoice.paid', $event->event);
    }

    public function test_missing_signature_header_fails(): void
    {
        $delivery = $this->signedDelivery();
        unset($delivery['headers']['X-CryptoPay-Signature']);

        $this->expectException(SignatureException::class);
        Webhook::verify($delivery['body'], $delivery['headers'], self::SECRET);
    }

    public function test_missing_timestamp_header_fails(): void
    {
        $delivery = $this->signedDelivery();
        unset($delivery['headers']['X-CryptoPay-Timestamp']);

        $this->expectException(SignatureException::class);
        Webhook::verify($delivery['body'], $delivery['headers'], self::SECRET);
    }

    public function test_non_numeric_timestamp_header_fails(): void
    {
        $delivery = $this->signedDelivery();
        $delivery['headers']['X-CryptoPay-Timestamp'] = 'not-a-number';

        $this->expectException(SignatureException::class);
        Webhook::verify($delivery['body'], $delivery['headers'], self::SECRET);
    }

    public function test_invalid_json_body_fails_even_with_correct_signature(): void
    {
        $body = 'not json';
        $timestamp = time();
        $signature = Webhook::sign(self::SECRET, $timestamp, $body);

        $headers = [
            'X-CryptoPay-Timestamp' => (string) $timestamp,
            'X-CryptoPay-Signature' => $signature,
        ];

        $this->expectException(SignatureException::class);
        Webhook::verify($body, $headers, self::SECRET);
    }

    /* ------------------------------------------------------------------
     * Hardening cases. Each of these was a way to get a forged delivery
     * accepted (or a check silently skipped) before verification was
     * tightened; they are the regression net for that.
     * --------------------------------------------------------------- */

    public function test_empty_secret_is_rejected_instead_of_hmac_with_an_empty_key(): void
    {
        // An attacker can compute this signature: the "secret" is public knowledge.
        $delivery = $this->signedDelivery('');

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessageMatches('/not configured/');

        Webhook::verify($delivery['body'], $delivery['headers'], '');
    }

    public function test_future_timestamp_beyond_tolerance_fails(): void
    {
        $delivery = $this->signedDelivery(null, time() + 3600);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessageMatches('/tolerance/');

        Webhook::verify($delivery['body'], $delivery['headers'], self::SECRET);
    }

    public function test_timestamps_inside_the_window_are_accepted_on_both_sides_of_now(): void
    {
        foreach ([time() - 120, time() + 120] as $timestamp) {
            $delivery = $this->signedDelivery(null, $timestamp);
            $event = Webhook::verify($delivery['body'], $delivery['headers'], self::SECRET);

            $this->assertInstanceOf(WebhookEvent::class, $event);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedSignatures(): array
    {
        return [
            'no scheme prefix' => [str_repeat('a', 64)],
            'wrong algorithm prefix' => ['sha1='.str_repeat('a', 64)],
            'too short' => ['sha256=abc'],
            'non-hex characters' => ['sha256='.str_repeat('z', 64)],
            'empty after prefix' => ['sha256='],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSignatures')]
    public function test_malformed_signature_header_is_rejected(string $signature): void
    {
        $delivery = $this->signedDelivery();
        $headers = $delivery['headers'];
        $headers['X-CryptoPay-Signature'] = $signature;

        $this->expectException(SignatureException::class);

        Webhook::verify($delivery['body'], $headers, self::SECRET);
    }

    public function test_upper_case_hex_signature_is_accepted(): void
    {
        $delivery = $this->signedDelivery();
        $headers = $delivery['headers'];
        $headers['X-CryptoPay-Signature'] = strtoupper($headers['X-CryptoPay-Signature']);

        $event = Webhook::verify($delivery['body'], $headers, self::SECRET);

        $this->assertSame('invoice.paid', $event->event);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTimestamps(): array
    {
        return [
            'float' => ['1893456000.5'],
            'exponent' => ['1e9'],
            'leading space' => [' 1893456000'],
            'negative' => ['-1893456000'],
            'hex' => ['0x70c8bd00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTimestamps')]
    public function test_malformed_timestamp_header_is_rejected(string $timestamp): void
    {
        $delivery = $this->signedDelivery();
        $headers = $delivery['headers'];
        $headers['X-CryptoPay-Timestamp'] = $timestamp;

        $this->expectException(SignatureException::class);

        Webhook::verify($delivery['body'], $headers, self::SECRET, 0);
    }

    public function test_signature_is_checked_before_the_body_is_parsed(): void
    {
        $delivery = $this->signedDelivery();
        $headers = $delivery['headers'];
        $headers['X-CryptoPay-Signature'] = 'sha256='.str_repeat('0', 64);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessageMatches('/signature mismatch/');

        Webhook::verify('not json at all', $headers, self::SECRET);
    }

    public function test_raw_body_is_used_verbatim_so_re_encoded_json_does_not_verify(): void
    {
        $delivery = $this->signedDelivery();
        // Same data, different bytes.
        $reEncoded = json_encode(json_decode($delivery['body'], true), JSON_PRETTY_PRINT);

        $this->assertNotSame($delivery['body'], $reEncoded);
        $this->expectException(SignatureException::class);

        Webhook::verify((string) $reEncoded, $delivery['headers'], self::SECRET);
    }

    /**
     * SPEC §6.2: a reorg can undo a payment the merchant was already told
     * about. `isReversed()` plus `reversal()` is what the handler branches on
     * to revoke whatever it credited for that invoice.
     */
    public function test_reversed_delivery_exposes_the_reversal_block(): void
    {
        $payload = $this->payload();
        $payload['event'] = 'invoice.reversed';
        $payload['data']['invoice']['status'] = 'pending';
        $payload['data']['invoice']['is_paid'] = false;
        $payload['data']['invoice']['paid_at'] = null;
        $payload['data']['reversal'] = [
            'transaction_id' => '01a06089-c3c9-7394-9750-480c130315fd',
            'tx_hash' => '0xdeadbeef',
            'amount' => '12.500000',
            'reason' => 'orphaned',
        ];

        $event = WebhookEvent::fromArray($payload);

        $this->assertTrue($event->isReversed());
        $this->assertFalse($event->isPaid());
        $this->assertTrue($event->isInvoiceEvent());
        $this->assertSame('orphaned', $event->reversal()['reason']);
        $this->assertSame('12.500000', $event->reversal()['amount']);
        $this->assertSame('pending', $event->invoice?->status);
    }

    public function test_a_paid_delivery_has_no_reversal_block(): void
    {
        $event = WebhookEvent::fromArray($this->payload());

        $this->assertFalse($event->isReversed());
        $this->assertNull($event->reversal());
    }

    public function test_exception_never_contains_the_secret(): void
    {
        $delivery = $this->signedDelivery('a-different-secret');

        try {
            Webhook::verify($delivery['body'], $delivery['headers'], self::SECRET);
            $this->fail('Expected a SignatureException.');
        } catch (SignatureException $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, (string) $e);
        }
    }
}
