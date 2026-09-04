<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Tests;

use CryptoPay\Sdk\Client;
use CryptoPay\Sdk\Dto\Invoice;
use CryptoPay\Sdk\Exception\ApiException;
use CryptoPay\Sdk\Http\Response;
use CryptoPay\Sdk\Tests\Fake\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const BASE_URL = 'http://localhost:8095';

    private const API_KEY = 'cp_live_ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';

    /**
     * The exact live Invoice payload from CONTRACT.md.
     *
     * @return array<mixed>
     */
    private function invoicePayload(array $overrides = []): array
    {
        return array_replace([
            'id' => '01a06089-c3c9-7394-9750-480c130315fd',
            'type' => 'payment',
            'external_id' => 'probe-1',
            'status' => 'pending',
            'is_paid' => false,
            'currency' => 'USDT',
            'network' => 'tron',
            'amount' => '12.500000',
            'amount_received' => '0.000000',
            'amount_confirmed' => '0.000000',
            'address' => 'TAofMk3exampleaddress',
            'payment_url' => 'http://localhost:8095/pay/01a06089-c3c9-7394-9750-480c130315fd',
            'qr_payload' => 'TAofMk3exampleaddress',
            'description' => null,
            'customer_email' => null,
            'customer_id' => null,
            'metadata' => ['a' => 1],
            'success_url' => null,
            'cancel_url' => null,
            'expires_at' => '2026-09-02T06:13:56+00:00',
            'paid_at' => null,
            'created_at' => '2026-09-02T05:13:56+00:00',
            'transactions' => [],
            'token_purchase' => null,
            'selection_required' => false,
        ], $overrides);
    }

    private function client(FakeTransport $transport): Client
    {
        return new Client(self::API_KEY, self::BASE_URL, ['transport' => $transport]);
    }

    private function jsonResponse(int $status, array $payload): Response
    {
        return new Response($status, ['content-type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    // 1. createInvoice builds POST {base}/api/v1/invoices, JSON body, headers; hydrates Invoice.
    public function test_create_invoice_sends_correct_request_and_hydrates_invoice(): void
    {
        $transport = new FakeTransport();
        $payload = $this->invoicePayload();
        $transport->queue($this->jsonResponse(201, ['data' => $payload]));

        $client = $this->client($transport);

        $invoice = $client->createInvoice(
            ['amount' => '12.5', 'currency' => 'USDT', 'network' => 'tron', 'external_id' => 'probe-1'],
            'idem-key-123'
        );

        $request = $transport->lastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->method);
        self::assertSame(self::BASE_URL.'/api/v1/invoices', $request->url);
        self::assertSame('Bearer '.self::API_KEY, $request->headers['Authorization']);
        self::assertSame('idem-key-123', $request->headers['Idempotency-Key']);
        self::assertSame('application/json', $request->headers['Content-Type']);

        $sentBody = json_decode((string) $request->body, true);
        self::assertSame('12.5', $sentBody['amount']);
        self::assertSame('probe-1', $sentBody['external_id']);

        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame($payload['id'], $invoice->id);
        self::assertSame('12.500000', $invoice->amount);
        self::assertIsString($invoice->amount);
    }

    public function test_create_invoice_without_idempotency_key_omits_header(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(201, ['data' => $this->invoicePayload()]));

        $this->client($transport)->createInvoice(['amount' => '1', 'currency' => 'USDT', 'network' => 'tron']);

        $request = $transport->lastRequest();
        self::assertArrayNotHasKey('Idempotency-Key', $request->headers);
    }

    // 2. is_paid true / status overpaid => isPaid() true; toArray() round-trips.
    public function test_invoice_is_paid_true_for_paid_and_overpaid_statuses(): void
    {
        $paid = Invoice::fromArray($this->invoicePayload(['status' => 'paid', 'is_paid' => true]));
        self::assertTrue($paid->isPaid());

        $overpaid = Invoice::fromArray($this->invoicePayload(['status' => 'overpaid', 'is_paid' => true]));
        self::assertTrue($overpaid->isPaid());

        $pending = Invoice::fromArray($this->invoicePayload());
        self::assertFalse($pending->isPaid());
        self::assertTrue($pending->isPending());
    }

    public function test_invoice_to_array_round_trips_the_raw_payload(): void
    {
        $payload = $this->invoicePayload(['status' => 'overpaid', 'is_paid' => true]);
        $invoice = Invoice::fromArray($payload);

        self::assertSame($payload, $invoice->toArray());
    }

    // 3. listInvoices sends filters as query, drops nulls, returns Paginated.
    public function test_list_invoices_sends_filters_as_query_and_drops_nulls(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, [
            'data' => [$this->invoicePayload(), $this->invoicePayload(['id' => 'second-id'])],
            'links' => ['first' => 'a', 'last' => 'b', 'prev' => null, 'next' => null],
            'meta' => ['current_page' => 1, 'last_page' => 3, 'per_page' => 2, 'total' => 5],
        ]));

        $page = $this->client($transport)->listInvoices([
            'status' => 'pending',
            'network' => 'tron',
            'external_id' => null,
            'per_page' => 2,
        ]);

        $request = $transport->lastRequest();
        self::assertStringStartsWith(self::BASE_URL.'/api/v1/invoices?', $request->url);
        self::assertStringContainsString('status=pending', $request->url);
        self::assertStringContainsString('network=tron', $request->url);
        self::assertStringContainsString('per_page=2', $request->url);
        self::assertStringNotContainsString('external_id', $request->url);

        self::assertCount(2, $page);
        self::assertSame(2, $page->count());
        self::assertSame(1, $page->currentPage());
        self::assertSame(3, $page->lastPage());
        self::assertSame(5, $page->total());
        self::assertTrue($page->hasMorePages());

        $collected = [];
        foreach ($page as $invoice) {
            self::assertInstanceOf(Invoice::class, $invoice);
            $collected[] = $invoice->id;
        }
        self::assertSame(['01a06089-c3c9-7394-9750-480c130315fd', 'second-id'], $collected);
    }

    public function test_list_invoices_bool_filter_is_cast_to_string(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, ['data' => [], 'links' => [], 'meta' => []]));

        $this->client($transport)->listInvoices(['is_paid' => true, 'unpaid' => false]);

        $request = $transport->lastRequest();
        self::assertStringContainsString('is_paid=true', $request->url);
        self::assertStringContainsString('unpaid=false', $request->url);
    }

    // 4. cancelInvoice hits POST .../invoices/{id}/cancel; 409 invalid_state maps to ApiException.
    public function test_cancel_invoice_hits_cancel_endpoint(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, ['data' => $this->invoicePayload(['status' => 'cancelled'])]));

        $invoice = $this->client($transport)->cancelInvoice('01a06089-c3c9-7394-9750-480c130315fd');

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame(self::BASE_URL.'/api/v1/invoices/01a06089-c3c9-7394-9750-480c130315fd/cancel', $request->url);
        self::assertTrue($invoice->isCancelled());
    }

    public function test_cancel_invoice_409_maps_to_invalid_state_api_exception(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(409, [
            'error' => ['code' => 'invalid_state', 'message' => 'Invoice cannot be cancelled.', 'details' => []],
        ]));

        $client = $this->client($transport);

        try {
            $client->cancelInvoice('some-id');
            self::fail('Expected ApiException to be thrown.');
        } catch (ApiException $e) {
            self::assertSame('invalid_state', $e->getErrorCode());
            self::assertSame(409, $e->getHttpStatus());
            self::assertSame('Invoice cannot be cancelled.', $e->getMessage());
        }
    }

    // 4b. selectInvoiceNetwork hits POST .../invoices/{id}/select and fills the pair in.
    public function test_select_invoice_network_sends_the_pair_and_hydrates_the_filled_in_invoice(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, ['data' => $this->invoicePayload([
            'currency' => 'USDC',
            'network' => 'bsc',
            'address' => '0xabc123',
            'qr_payload' => '0xabc123',
            'selection_required' => false,
        ])]));

        $invoice = $this->client($transport)->selectInvoiceNetwork(
            '01a06089-c3c9-7394-9750-480c130315fd',
            ['currency' => 'USDC', 'network' => 'bsc']
        );

        $request = $transport->lastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->method);
        self::assertSame(self::BASE_URL.'/api/v1/invoices/01a06089-c3c9-7394-9750-480c130315fd/select', $request->url);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame(['currency' => 'USDC', 'network' => 'bsc'], json_decode((string) $request->body, true));

        self::assertSame('USDC', $invoice->currency);
        self::assertSame('bsc', $invoice->network);
        self::assertSame('0xabc123', $invoice->address);
        self::assertFalse($invoice->needsSelection());
    }

    public function test_select_invoice_network_url_encodes_the_id(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, ['data' => $this->invoicePayload()]));

        $this->client($transport)->selectInvoiceNetwork('id with space/slash', ['currency' => 'USDT', 'network' => 'tron']);

        $request = $transport->lastRequest();
        self::assertSame(self::BASE_URL.'/api/v1/invoices/id%20with%20space%2Fslash/select', $request->url);
    }

    public function test_select_invoice_network_409_maps_to_invalid_state_api_exception(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(409, [
            'error' => ['code' => 'invalid_state', 'message' => 'Currency and network are already selected.', 'details' => []],
        ]));

        try {
            $this->client($transport)->selectInvoiceNetwork('some-id', ['currency' => 'USDT', 'network' => 'tron']);
            self::fail('Expected ApiException to be thrown.');
        } catch (ApiException $e) {
            self::assertSame('invalid_state', $e->getErrorCode());
            self::assertSame(409, $e->getHttpStatus());
        }
    }

    public function test_select_invoice_network_422_maps_to_validation_error(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(422, [
            'error' => [
                'code' => 'validation_error',
                'message' => 'The given data was invalid.',
                'details' => ['network' => ['The selected network is invalid.']],
            ],
        ]));

        try {
            $this->client($transport)->selectInvoiceNetwork('some-id', ['currency' => 'USDT', 'network' => 'solana']);
            self::fail('Expected ApiException to be thrown.');
        } catch (ApiException $e) {
            self::assertTrue($e->isValidationError());
            self::assertSame(['network' => ['The selected network is invalid.']], $e->getDetails());
        }
    }

    // 4c. An invoice created without currency/network hydrates with nulls.
    public function test_create_invoice_without_currency_and_network_hydrates_nulls_and_selection_required(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(201, ['data' => $this->invoicePayload([
            'currency' => null,
            'network' => null,
            'address' => null,
            'qr_payload' => null,
            'selection_required' => true,
        ])]));

        $invoice = $this->client($transport)->createInvoice(['amount' => '12.5', 'external_id' => 'probe-1']);

        $sentBody = json_decode((string) $transport->lastRequest()->body, true);
        self::assertArrayNotHasKey('currency', $sentBody);
        self::assertArrayNotHasKey('network', $sentBody);

        self::assertNull($invoice->currency);
        self::assertNull($invoice->network);
        self::assertNull($invoice->address);
        self::assertNull($invoice->qrPayload);
        self::assertTrue($invoice->selectionRequired);
        self::assertTrue($invoice->needsSelection());
        self::assertTrue($invoice->isPending());
    }

    // 5. 422 validation_error => details + isValidationError() true.
    public function test_validation_error_exposes_field_details(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(422, [
            'error' => [
                'code' => 'validation_error',
                'message' => 'The given data was invalid.',
                'details' => ['amount' => ['The amount field is required.']],
            ],
        ]));

        try {
            $this->client($transport)->createInvoice(['currency' => 'USDT', 'network' => 'tron']);
            self::fail('Expected ApiException to be thrown.');
        } catch (ApiException $e) {
            self::assertTrue($e->isValidationError());
            self::assertSame(['amount' => ['The amount field is required.']], $e->getDetails());
            self::assertSame(422, $e->getHttpStatus());
        }
    }

    // 6. 401 unauthenticated => code unauthenticated.
    public function test_unauthenticated_error(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(401, [
            'error' => ['code' => 'unauthenticated', 'message' => 'Invalid or revoked API key.', 'details' => []],
        ]));

        try {
            $this->client($transport)->getInvoice('any-id');
            self::fail('Expected ApiException to be thrown.');
        } catch (ApiException $e) {
            self::assertSame('unauthenticated', $e->getErrorCode());
            self::assertSame(401, $e->getHttpStatus());
            self::assertFalse($e->isNotFound());
        }
    }

    public function test_not_found_and_rate_limited_helpers(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(404, [
            'error' => ['code' => 'not_found', 'message' => 'Resource not found.', 'details' => []],
        ]));
        try {
            $this->client($transport)->getInvoice('missing');
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertTrue($e->isNotFound());
        }

        $transport2 = new FakeTransport();
        $transport2->queue($this->jsonResponse(429, [
            'error' => ['code' => 'rate_limited', 'message' => 'Too many requests.', 'details' => []],
        ]));
        try {
            $this->client($transport2)->getInvoice('any');
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertTrue($e->isRateLimited());
        }
    }

    // 7. non-JSON 500 body => code server_error, raw body in details.
    public function test_non_json_server_error_falls_back_to_server_error_code(): void
    {
        $transport = new FakeTransport();
        $transport->queue(new Response(500, [], '<html>Internal Server Error</html>'));

        try {
            $this->client($transport)->getInvoice('any-id');
            self::fail('Expected ApiException to be thrown.');
        } catch (ApiException $e) {
            self::assertSame('server_error', $e->getErrorCode());
            self::assertSame(500, $e->getHttpStatus());
            self::assertSame('<html>Internal Server Error</html>', $e->getDetails()['body']);
        }
    }

    public function test_non_json_4xx_body_falls_back_to_http_error_code(): void
    {
        $transport = new FakeTransport();
        $transport->queue(new Response(418, [], 'not json at all'));

        try {
            $this->client($transport)->getInvoice('any-id');
            self::fail('Expected ApiException to be thrown.');
        } catch (ApiException $e) {
            self::assertSame('http_error', $e->getErrorCode());
            self::assertSame(418, $e->getHttpStatus());
        }
    }

    // 8. balances(), networks(), tokens(), customerHoldings(), me().
    public function test_balances_parses_data_and_totals(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, [
            'data' => [
                ['currency' => 'USDT', 'network' => 'tron', 'available' => '100.5', 'pending' => '0'],
                ['currency' => 'USDC', 'network' => 'bsc', 'available' => '50', 'pending' => '10'],
            ],
            'totals' => [
                'USDT' => ['available' => '100.5', 'pending' => '0'],
                'USDC' => ['available' => '50', 'pending' => '10'],
            ],
        ]));

        $balances = $this->client($transport)->balances();

        self::assertCount(2, $balances->data);
        self::assertSame('100.5', $balances->total('USDT')['available']);
        self::assertSame(['available' => '0', 'pending' => '0'], $balances->total('EUR'));
    }

    public function test_networks_tokens_and_holdings_parse_bare_data_array(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, ['data' => [
            ['code' => 'tron', 'name' => 'Tron', 'chain_id' => null, 'confirmations_required' => 19, 'tokens' => []],
        ]]));
        $networks = $this->client($transport)->networks();
        self::assertCount(1, $networks);
        self::assertSame('tron', $networks[0]->code);

        $transport2 = new FakeTransport();
        $transport2->queue($this->jsonResponse(200, ['data' => [
            ['id' => 'tok-1', 'symbol' => 'MTK', 'name' => 'MyToken', 'price_usd' => '1.5', 'decimals' => 18, 'sold' => '0', 'is_active' => true],
        ]]));
        $tokens = $this->client($transport2)->tokens();
        self::assertCount(1, $tokens);
        self::assertSame('MTK', $tokens[0]->symbol);

        $transport3 = new FakeTransport();
        $transport3->queue($this->jsonResponse(200, ['data' => [
            ['token' => null, 'customer_id' => 'cust with space/slash', 'amount' => '5', 'updated_at' => null],
        ]]));
        $holdings = $this->client($transport3)->customerHoldings('cust with space/slash');
        self::assertCount(1, $holdings);
        self::assertSame('5', $holdings[0]->amount);
    }

    public function test_me_parses_merchant_and_webhook_block(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, ['data' => [
            'id' => 'merchant-1',
            'name' => 'Acme',
            'email' => 'acme@example.com',
            'webhook_url' => 'https://acme.test/hook',
            'is_active' => true,
            'settings' => [],
            'underpayment_tolerance' => '0.01',
            'created_at' => '2026-01-01T00:00:00+00:00',
            'balances' => [],
            'webhook' => [
                'url' => 'https://acme.test/hook',
                'configured' => true,
                'events' => ['invoice.paid'],
                'signature_header' => 'X-CryptoPay-Signature',
            ],
            'api_key' => null,
        ]]));

        $merchant = $this->client($transport)->me();

        self::assertSame('Acme', $merchant->name);
        self::assertTrue($merchant->webhook['configured']);
        self::assertSame(['invoice.paid'], $merchant->webhook['events']);
    }

    // 9. createTokenPurchase parses the UNWRAPPED {purchase, invoice} envelope.
    public function test_create_token_purchase_parses_unwrapped_envelope(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(201, [
            'purchase' => [
                'id' => 'purchase-1',
                'invoice_id' => 'invoice-1',
                'token_id' => 'token-1',
                'merchant_id' => 'merchant-1',
                'customer_id' => 'customer-1',
                'customer_email' => null,
                'token_amount' => '100',
                'price_usd' => '1.5',
                'pay_amount' => '150',
                'currency' => 'USDT',
                'status' => 'pending',
                'completed_at' => null,
                'created_at' => '2026-01-01T00:00:00+00:00',
                'token' => null,
            ],
            'invoice' => $this->invoicePayload(['id' => 'invoice-1', 'type' => 'token_purchase']),
        ]));

        $result = $this->client($transport)->createTokenPurchase(
            ['token_id' => 'token-1', 'token_amount' => '100', 'currency' => 'USDT', 'network' => 'tron', 'customer_id' => 'customer-1'],
            'idem-tp-1'
        );

        $request = $transport->lastRequest();
        self::assertSame(self::BASE_URL.'/api/v1/token-purchases', $request->url);
        self::assertSame('idem-tp-1', $request->headers['Idempotency-Key']);

        self::assertSame('purchase-1', $result->purchase->id);
        self::assertSame('invoice-1', $result->invoice->id);
        self::assertSame('token_purchase', $result->invoice->type);
    }

    // 11. Path segments are URL-encoded.
    public function test_path_segments_are_url_encoded(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, ['data' => $this->invoicePayload()]));

        $this->client($transport)->getInvoice('id with space/slash');

        $request = $transport->lastRequest();
        self::assertSame(self::BASE_URL.'/api/v1/invoices/id%20with%20space%2Fslash', $request->url);
    }

    public function test_customer_id_path_segment_is_url_encoded(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, ['data' => []]));

        $this->client($transport)->customerHoldings('cust/with space');

        $request = $transport->lastRequest();
        self::assertSame(self::BASE_URL.'/api/v1/customers/cust%2Fwith%20space/holdings', $request->url);
    }

    public function test_base_url_already_ending_in_api_v1_is_not_doubled(): void
    {
        $transport = new FakeTransport();
        $transport->queue($this->jsonResponse(200, ['data' => $this->invoicePayload()]));

        $client = new Client(self::API_KEY, self::BASE_URL.'/api/v1/', ['transport' => $transport]);
        $client->getInvoice('abc');

        $request = $transport->lastRequest();
        self::assertSame(self::BASE_URL.'/api/v1/invoices/abc', $request->url);
    }

    public function test_constructor_rejects_empty_api_key_and_base_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('', self::BASE_URL);
    }

    public function test_constructor_rejects_empty_base_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('cp_live_x', '');
    }

    /* ------------------------------------------------------------------
     * Transport hardening: key handling, base URL, rate limiting.
     * --------------------------------------------------------------- */

    public function test_api_key_is_sent_only_in_the_authorization_header_never_in_the_url(): void
    {
        $transport = (new FakeTransport)->queue($this->jsonResponse(200, ['data' => [], 'meta' => []]));

        $this->client($transport)->listInvoices(['status' => 'pending']);

        $request = $transport->lastRequest();
        $this->assertNotNull($request);
        $this->assertStringNotContainsString(self::API_KEY, $request->url);
        $this->assertSame('Bearer '.self::API_KEY, $request->headers['Authorization']);
    }

    public function test_rate_limited_response_exposes_retry_after_and_is_not_retried(): void
    {
        $transport = (new FakeTransport)->handleWith(fn (): Response => new Response(
            429,
            ['content-type' => 'application/json', 'retry-after' => '30'],
            json_encode(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests', 'details' => []]], JSON_THROW_ON_ERROR)
        ));

        try {
            $this->client($transport)->listInvoices();
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertTrue($e->isRateLimited());
            $this->assertSame(30, $e->getRetryAfter());
        }

        // No hidden retry loop: exactly one request left the SDK.
        $this->assertSame(1, $transport->requestCount());
    }

    public function test_retry_after_is_null_when_the_header_is_absent_or_not_a_plain_number(): void
    {
        $transport = (new FakeTransport)->handleWith(fn (): Response => new Response(
            429,
            ['content-type' => 'application/json', 'retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'],
            json_encode(['error' => ['code' => 'rate_limited', 'message' => 'slow down', 'details' => []]], JSON_THROW_ON_ERROR)
        ));

        try {
            $this->client($transport)->listInvoices();
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertNull($e->getRetryAfter());
        }
    }

    public function test_constructor_rejects_a_base_url_that_is_not_http_or_https(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client(self::API_KEY, 'file:///etc/passwd', ['transport' => new FakeTransport]);
    }

    public function test_constructor_rejects_a_base_url_without_a_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client(self::API_KEY, 'pay.example.com', ['transport' => new FakeTransport]);
    }

    public function test_plain_http_against_a_non_local_host_warns_but_still_constructs(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            new Client(self::API_KEY, 'http://pay.example.com', ['transport' => new FakeTransport]);
            $this->assertCount(1, $warnings);
            $this->assertStringContainsString('unencrypted', $warnings[0]);

            $warnings = [];
            new Client(self::API_KEY, 'http://localhost:8095', ['transport' => new FakeTransport]);
            new Client(self::API_KEY, 'http://127.0.0.1:8095', ['transport' => new FakeTransport]);
            new Client(self::API_KEY, 'https://pay.example.com', ['transport' => new FakeTransport]);
            $this->assertSame([], $warnings);
        } finally {
            restore_error_handler();
        }
    }

    public function test_var_dump_of_the_client_does_not_expose_the_api_key(): void
    {
        $client = $this->client(new FakeTransport);

        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::API_KEY, $dump);
        $this->assertStringContainsString('redacted', $dump);
    }

    public function test_api_exception_never_contains_the_api_key(): void
    {
        $transport = (new FakeTransport)->queue($this->jsonResponse(401, [
            'error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.', 'details' => []],
        ]));

        try {
            $this->client($transport)->listInvoices();
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, json_encode($e->getDetails(), JSON_THROW_ON_ERROR));
        }
    }
}
