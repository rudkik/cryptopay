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
}
