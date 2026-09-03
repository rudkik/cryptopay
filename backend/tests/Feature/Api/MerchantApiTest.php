<?php

namespace Tests\Feature\Api;

use App\Models\Invoice;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantApiTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();

        [$this->merchant, $this->key] = $this->makeMerchant();
    }

    public function test_networks_lists_only_enabled_networks_with_their_contracts(): void
    {
        $this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/networks')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['code', 'name', 'chain_id', 'confirmations_required', 'tokens' => [['symbol', 'contract_address', 'decimals']]]]]);
    }

    public function test_balances_include_per_currency_totals(): void
    {
        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->json('data.id');

        $invoice = Invoice::with('depositAddress')->findOrFail($id);

        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', [
            'network' => 'tron', 'tx_hash' => $this->txHash('bal'), 'log_index' => 0, 'symbol' => 'USDT',
            'to_address' => $invoice->depositAddress->address, 'amount' => '10',
            'amount_raw' => '10000000', 'confirmations' => 19, 'status' => 'confirmed',
        ])->assertOk();

        $this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/balances')
            ->assertOk()
            ->assertJsonPath('data.0.currency', 'USDT')
            ->assertJsonPath('data.0.network', 'tron')
            ->assertJsonPath('data.0.available', '10.000000')
            ->assertJsonPath('data.0.pending', '0.000000')
            ->assertJsonPath('totals.USDT.available', '10.000000');
    }

    public function test_transactions_are_scoped_to_the_merchant(): void
    {
        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->json('data.id');

        $invoice = Invoice::with('depositAddress')->findOrFail($id);

        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', [
            'network' => 'tron', 'tx_hash' => $this->txHash('scope'), 'log_index' => 0, 'symbol' => 'USDT',
            'to_address' => $invoice->depositAddress->address, 'amount' => '10',
            'amount_raw' => '10000000', 'confirmations' => 3, 'status' => 'detected',
        ])->assertOk();

        $this->withHeaders($this->keyHeaders($this->key))
            ->getJson("/api/v1/transactions?invoice_id={$id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tx_hash', $this->txHash('scope'))
            ->assertJsonPath('data.0.confirmations', 3)
            ->assertJsonPath('data.0.confirmations_required', 19)
            ->assertJsonPath('data.0.status', 'detected')
            ->assertJsonPath('data.0.explorer_url', 'https://tronscan.org/#/transaction/'.$this->txHash('scope'));

        [, $otherKey] = $this->makeMerchant();

        $this->withHeaders($this->keyHeaders($otherKey))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_me_describes_the_merchant_and_its_webhook_setup(): void
    {
        $this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->merchant->id)
            ->assertJsonPath('data.webhook.configured', false)
            ->assertJsonPath('data.underpayment_tolerance', '0.500000000000000000')
            ->assertJsonPath('data.api_key.name', 'Test key');

        // The webhook secret must never leak through this endpoint.
        $this->assertStringNotContainsString(
            $this->merchant->webhook_secret,
            $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/me')->getContent(),
        );
    }

    public function test_the_api_key_records_when_it_was_last_used(): void
    {
        $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/networks')->assertOk();

        $this->assertNotNull($this->merchant->apiKeys()->firstOrFail()->last_used_at);
    }

    public function test_a_deactivated_merchant_is_locked_out(): void
    {
        $this->merchant->forceFill(['is_active' => false])->save();

        $this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/networks')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }
}
