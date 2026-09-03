<?php

declare(strict_types=1);

/**
 * Zero-dependency webhook receiver for local testing.
 *
 *     php -S 127.0.0.1:8099 examples/webhook-server.php
 *
 * Point CryptoPay's merchant webhook_url at http://127.0.0.1:8099/ (or
 * forward it through a tunnel), send a test delivery, and this script will
 * verify the signature and print the decoded event.
 */

require __DIR__.'/../vendor/autoload.php';

use CryptoPay\Sdk\Exception\SignatureException;
use CryptoPay\Sdk\Webhook;

$secret = getenv('CRYPTOPAY_WEBHOOK_SECRET') ?: '';

if ($secret === '') {
    error_log('Set CRYPTOPAY_WEBHOOK_SECRET before starting this server.');
    http_response_code(500);
    exit('CRYPTOPAY_WEBHOOK_SECRET is not set.');
}

/**
 * @return array<string, string>
 */
function collectHeaders(): array
{
    if (function_exists('getallheaders')) {
        /** @var array<string, string>|false $headers */
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (is_string($key) && (str_starts_with($key, 'HTTP_') || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true))) {
            $headers[$key] = (string) $value;
        }
    }

    return $headers;
}

$rawBody = file_get_contents('php://input') ?: '';
$headers = collectHeaders();

header('Content-Type: application/json');

try {
    $event = Webhook::verify($rawBody, $headers, $secret);
} catch (SignatureException $e) {
    // The precise reason goes to the log, never to the caller: telling an
    // unauthenticated client whether it got the timestamp or the signature
    // wrong only helps someone probing the endpoint.
    http_response_code(400);
    echo json_encode([
        'error' => [
            'code' => 'invalid_signature',
            'message' => 'Invalid webhook signature.',
        ],
    ]);

    error_log('Rejected webhook: '.$e->getMessage());
    exit;
}

error_log(sprintf(
    'event=%s delivery=%s invoice=%s',
    $event->event,
    $event->deliveryId ?? '(none)',
    $event->invoice?->id ?? '(none)'
));

echo json_encode(['received' => true, 'event' => $event->event]);
