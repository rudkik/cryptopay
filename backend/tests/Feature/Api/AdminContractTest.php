<?php

namespace Tests\Feature\Api;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks the shapes the Vue admin panel reads. These are additive extras on top
 * of the SPEC objects; if one disappears the admin UI breaks silently, so each
 * one is asserted explicitly.
 */
class AdminContractTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Merchant $merchant;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();

        $this->token = $this->adminToken(User::factory()->create());

        [$this->merchant, $key] = $this->makeMerchant([
            'name' => 'Acme Ltd',
            'webhook_url' => 'https://merchant.test/hooks',
        ]);

        $id = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '100', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()->json('data.id');

        $this->invoice = Invoice::with('depositAddress')->findOrFail($id);

        // One transaction and, via the status change, one webhook delivery.
        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', [
            'network' => 'tron', 'tx_hash' => 'tx-contract', 'log_index' => 0, 'symbol' => 'USDT',
            'to_address' => $this->invoice->depositAddress->address, 'amount' => '100',
            'amount_raw' => '100000000', 'confirmations' => 19, 'status' => 'confirmed',
        ])->assertOk();

        Token::factory()->create(['merchant_id' => $this->merchant->id, 'symbol' => 'DEMO']);
    }

    public static function paginatedEndpoints(): array
    {
        return [
            'merchants' => ['/api/admin/merchants'],
            'invoices' => ['/api/admin/invoices'],
            'transactions' => ['/api/admin/transactions'],
            'tokens' => ['/api/admin/tokens'],
            'token-purchases' => ['/api/admin/token-purchases'],
            'webhooks' => ['/api/admin/webhooks'],
            'users' => ['/api/admin/users'],
            'ledger' => ['/api/admin/ledger'],
        ];
    }

    #[DataProvider('paginatedEndpoints')]
    public function test_admin_list_endpoints_are_paginated(string $url): void
    {
        $this->withToken($this->token)
            ->getJson($url)
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_admin_rows_carry_the_owning_merchant(): void
    {
        $this->withToken($this->token)->getJson('/api/admin/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.merchant.id', $this->merchant->id)
            ->assertJsonPath('data.0.merchant.name', 'Acme Ltd');

        $this->withToken($this->token)->getJson('/api/admin/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.merchant.name', 'Acme Ltd')
            ->assertJsonPath('data.0.confirmations_required', 19)
            ->assertJsonPath('data.0.explorer_url', 'https://tronscan.org/#/transaction/tx-contract');

        $this->withToken($this->token)->getJson('/api/admin/tokens')
            ->assertOk()
            ->assertJsonPath('data.0.merchant.name', 'Acme Ltd');

        $this->withToken($this->token)->getJson('/api/admin/webhooks')
            ->assertOk()
            ->assertJsonPath('data.0.merchant.name', 'Acme Ltd');
    }

    public function test_the_invoice_detail_carries_transactions_and_webhooks(): void
    {
        $this->withToken($this->token)
            ->getJson("/api/admin/invoices/{$this->invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.merchant.name', 'Acme Ltd')
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonCount(1, 'data.webhooks')
            ->assertJsonPath('data.webhooks.0.event', 'invoice.paid');
    }

    public function test_the_merchant_detail_carries_balances_and_api_keys(): void
    {
        $this->withToken($this->token)
            ->getJson("/api/admin/merchants/{$this->merchant->id}")
            ->assertOk()
            ->assertJsonPath('data.webhook_url', 'https://merchant.test/hooks')
            ->assertJsonCount(1, 'data.balances')
            ->assertJsonPath('data.balances.0.currency', 'USDT')
            ->assertJsonCount(1, 'data.api_keys')
            ->assertJsonStructure(['data' => ['api_keys' => [['id', 'name', 'key_prefix', 'revoked_at']]]]);
    }

    public function test_networks_carry_their_token_contracts(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/admin/networks')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [[
                'code', 'name', 'chain_id', 'confirmations_required', 'is_enabled',
                'explorer_tx_url', 'explorer_address_url', 'last_scanned_block',
                'watcher_healthy', 'watcher_seen_at',
                'token_contracts' => [['id', 'symbol', 'contract_address', 'decimals', 'is_enabled']],
            ]]]);
    }

    public function test_holdings_and_rotate_and_me_return_their_expected_shapes(): void
    {
        $token = Token::query()->firstOrFail();

        $this->withToken($this->token)
            ->getJson("/api/admin/tokens/{$token->id}/holdings")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->withToken($this->token)
            ->postJson("/api/admin/merchants/{$this->merchant->id}/webhook-secret/rotate")
            ->assertOk()
            ->assertJsonStructure(['webhook_secret']);

        $this->withToken($this->token)
            ->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role', 'is_active']]);
    }

    public function test_simulate_payment_returns_the_updated_invoice_resource(): void
    {
        [, $key] = $this->makeMerchant();

        $id = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '5', 'currency' => 'USDT', 'network' => 'tron'])
            ->json('data.id');

        $this->withToken($this->token)
            ->postJson("/api/admin/invoices/{$id}/simulate-payment", ['confirmed' => true])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.is_paid', true)
            ->assertJsonPath('invoice.id', $id);
    }

    public function test_empty_filter_values_from_the_ui_are_ignored(): void
    {
        // The admin UI sends unset selects as empty strings, and pagination
        // params the API does not declare.
        $this->withToken($this->token)
            ->getJson('/api/admin/invoices?status=&network=&currency=&merchant_id=&q=&page=1&per_page=25&sort=whatever')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($this->token)
            ->getJson('/api/admin/transactions?network=&status=&invoice_id=&merchant_id=&q=')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($this->token)
            ->getJson('/api/admin/webhooks?merchant_id=&status=&event=')
            ->assertOk();

        $this->withToken($this->token)
            ->getJson('/api/admin/token-purchases?token_id=&merchant_id=&status=')
            ->assertOk();
    }
}
