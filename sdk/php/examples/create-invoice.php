<?php

declare(strict_types=1);

/**
 * Runnable example: create an invoice, fetch it back, list invoices,
 * print balances, then cancel the invoice.
 *
 *     php examples/create-invoice.php
 *
 * Reads CRYPTOPAY_API_KEY / CRYPTOPAY_BASE_URL from the environment,
 * defaulting to the live local dev stack.
 */

require __DIR__.'/../vendor/autoload.php';

use CryptoPay\Sdk\Client;
use CryptoPay\Sdk\Exception\ApiException;
use CryptoPay\Sdk\Exception\CryptoPayException;

$apiKey = getenv('CRYPTOPAY_API_KEY') ?: 'cp_live_ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';
$baseUrl = getenv('CRYPTOPAY_BASE_URL') ?: 'http://localhost:8095';

$client = new Client($apiKey, $baseUrl);

try {
    echo "== Создание счёта ==\n";
    $invoice = $client->createInvoice([
        'amount' => '12.5',
        'currency' => 'USDT',
        'network' => 'tron',
        'external_id' => 'sdk-example-'.bin2hex(random_bytes(4)),
        'description' => 'CryptoPay SDK example invoice',
        'metadata' => ['source' => 'sdk-example'],
    ], idempotencyKey: bin2hex(random_bytes(16)));

    printf("id:          %s\n", $invoice->id);
    printf("status:      %s\n", $invoice->status);
    printf("address:     %s\n", $invoice->address ?? '(none)');
    printf("payment_url: %s\n", $invoice->paymentUrl ?? '(none)');
    printf("amount:      %s %s\n\n", $invoice->amount, $invoice->currency);

    echo "== Повторное получение счёта ==\n";
    $fetched = $client->getInvoice($invoice->id);
    printf("status: %s, is_paid: %s\n\n", $fetched->status, $fetched->isPaid() ? 'true' : 'false');

    echo "== Список последних счетов ==\n";
    $page = $client->listInvoices(['per_page' => 5]);
    printf("total: %d, current_page: %d, last_page: %d\n", $page->total(), $page->currentPage(), $page->lastPage());
    foreach ($page as $item) {
        printf("  - %s [%s] %s %s\n", $item->id, $item->status, $item->amount, $item->currency);
    }
    echo "\n";

    echo "== Балансы ==\n";
    $balances = $client->balances();
    foreach ($balances->data as $balance) {
        printf("  %s/%s available=%s pending=%s\n", $balance->currency, $balance->network, $balance->available, $balance->pending);
    }
    foreach (['USDT', 'USDC'] as $currency) {
        $total = $balances->total($currency);
        printf("  total %s: available=%s pending=%s\n", $currency, $total['available'], $total['pending']);
    }
    echo "\n";

    echo "== Отмена счёта ==\n";
    $cancelled = $client->cancelInvoice($invoice->id);
    printf("status after cancel: %s (isCancelled: %s)\n", $cancelled->status, $cancelled->isCancelled() ? 'true' : 'false');
} catch (ApiException $e) {
    fwrite(STDERR, sprintf("API error [%s] (HTTP %d): %s\n", $e->getErrorCode(), $e->getHttpStatus(), $e->getMessage()));
    fwrite(STDERR, json_encode($e->getDetails(), JSON_PRETTY_PRINT)."\n");
    exit(1);
} catch (CryptoPayException $e) {
    fwrite(STDERR, 'Transport error: '.$e->getMessage()."\n");
    exit(1);
}
