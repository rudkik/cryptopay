<?php

namespace Tests\Feature\Api;

use App\Models\Invoice;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();
    }

    public function test_a_merchant_creates_an_invoice_with_its_api_key(): void
    {
        [, $key] = $this->makeMerchant();

        $response = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', [
                'amount' => '100.5',
                'currency' => 'USDT',
                'network' => 'tron',
                'external_id' => 'order-1',
                'description' => 'Test order',
                'metadata' => ['sku' => 'abc'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'payment')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_paid', false)
            ->assertJsonPath('data.currency', 'USDT')
            ->assertJsonPath('data.network', 'tron')
            // Amounts are strings formatted to the token's decimals, never floats.
            ->assertJsonPath('data.amount', '100.500000')
            ->assertJsonPath('data.amount_received', '0.000000')
            ->assertJsonPath('data.external_id', 'order-1')
            ->assertJsonPath('data.metadata.sku', 'abc')
            ->assertJsonStructure(['data' => [
                'id', 'address', 'payment_url', 'qr_payload', 'expires_at', 'transactions',
            ]]);

        $this->assertSame(
            'http://localhost:8080/pay/'.$response->json('data.id'),
            $response->json('data.payment_url'),
        );

        // Tron qr_payload is the bare address.
        $this->assertSame($response->json('data.address'), $response->json('data.qr_payload'));

        $this->assertSame(1, Wallet::where('network_code', 'tron')->value('next_index'));
    }

    public function test_evm_invoices_expose_an_eip_681_qr_payload(): void
    {
        [, $key] = $this->makeMerchant();

        $response = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '25', 'currency' => 'USDC', 'network' => 'ethereum']);

        $response->assertCreated();

        $this->assertSame(
            'ethereum:0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48@1/transfer?address='
                .$response->json('data.address').'&uint256=25000000',
            $response->json('data.qr_payload'),
        );
    }

    public function test_requests_without_a_valid_api_key_are_rejected(): void
    {
        $this->postJson('/api/v1/invoices', [])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->withHeaders($this->keyHeaders('cp_live_'.str_repeat('ff', 20)))
            ->getJson('/api/v1/invoices')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_validation_errors_use_the_spec_error_envelope(): void
    {
        [, $key] = $this->makeMerchant();

        $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '0', 'currency' => 'EUR', 'network' => 'solana'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['amount', 'currency', 'network']]]);
    }

    public function test_an_invoice_can_be_cancelled_only_while_pending(): void
    {
        [, $key] = $this->makeMerchant();

        $id = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->json('data.id');

        $this->withHeaders($this->keyHeaders($key))
            ->postJson("/api/v1/invoices/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->withHeaders($this->keyHeaders($key))
            ->postJson("/api/v1/invoices/{$id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');
    }

    public function test_a_merchant_cannot_read_another_merchants_invoice(): void
    {
        [, $keyA] = $this->makeMerchant();
        [, $keyB] = $this->makeMerchant();

        $id = $this->withHeaders($this->keyHeaders($keyA))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->json('data.id');

        $this->withHeaders($this->keyHeaders($keyB))
            ->getJson("/api/v1/invoices/{$id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_the_invoice_list_is_filtered_and_paginated(): void
    {
        [, $key] = $this->makeMerchant();

        foreach (['a', 'b', 'c'] as $external) {
            $this->withHeaders($this->keyHeaders($key))->postJson('/api/v1/invoices', [
                'amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'external_id' => $external,
            ])->assertCreated();
        }

        $this->withHeaders($this->keyHeaders($key))
            ->getJson('/api/v1/invoices?external_id=b')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'b')
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'total']]);
    }

    public function test_an_unreachable_watcher_surfaces_as_a_503(): void
    {
        $this->resetHttpFakes();
        Http::fake(['*/addresses/derive' => Http::response(['error' => 'nope'], 500)]);

        [, $key] = $this->makeMerchant();

        $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'watcher_unavailable');

        // The derivation index must not be burned by a failed allocation.
        $this->assertSame(0, Wallet::where('network_code', 'tron')->value('next_index'));
        $this->assertSame(0, Invoice::count());
    }

    public function test_the_idempotency_key_replays_the_first_response(): void
    {
        [, $key] = $this->makeMerchant();

        $payload = ['amount' => '42', 'currency' => 'USDT', 'network' => 'tron'];
        $headers = $this->keyHeaders($key, ['Idempotency-Key' => 'abc-123']);

        $first = $this->withHeaders($headers)->postJson('/api/v1/invoices', $payload)->assertCreated();
        $second = $this->withHeaders($headers)->postJson('/api/v1/invoices', $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Invoice::count());
    }

    public function test_the_public_checkout_endpoint_hides_customer_data(): void
    {
        [, $key] = $this->makeMerchant();

        $id = $this->withHeaders($this->keyHeaders($key))->postJson('/api/v1/invoices', [
            'amount' => '10', 'currency' => 'USDT', 'network' => 'tron',
            'customer_email' => 'buyer@example.com', 'customer_id' => 'cust-1',
            'metadata' => ['secret' => 'do-not-leak'],
        ])->json('data.id');

        $response = $this->getJson("/api/public/invoices/{$id}")->assertOk();

        $response->assertJsonPath('data.network_name', 'Tron')
            ->assertJsonPath('data.token_contract.symbol', 'USDT')
            ->assertJsonPath('data.confirmations_required', 19);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('metadata', $data);
        $this->assertArrayNotHasKey('customer_email', $data);
        $this->assertArrayNotHasKey('customer_id', $data);
    }
}
