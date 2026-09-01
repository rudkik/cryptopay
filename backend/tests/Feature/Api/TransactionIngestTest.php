<?php

namespace Tests\Feature\Api;

use App\Models\Balance;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionIngestTest extends TestCase
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

    private function createInvoice(array $overrides = []): Invoice
    {
        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', array_merge([
                'amount' => '100',
                'currency' => 'USDT',
                'network' => 'tron',
            ], $overrides))
            ->assertCreated()
            ->json('data.id');

        return Invoice::with('depositAddress')->findOrFail($id);
    }

    private function payload(Invoice $invoice, string $amount, string $status = 'detected', array $overrides = []): array
    {
        return array_merge([
            'network' => $invoice->network_code,
            'tx_hash' => 'tx-'.substr(md5($invoice->id.$amount), 0, 32),
            'log_index' => 0,
            'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'symbol' => $invoice->currency,
            'from_address' => 'TFromAddress0000000000000000000000',
            'to_address' => $invoice->depositAddress->address,
            'amount_raw' => bcmul($amount, '1000000', 0),
            'amount' => $amount,
            'block_number' => 1001,
            'block_hash' => 'block-hash',
            'confirmations' => $status === 'confirmed' ? 19 : 1,
            'status' => $status,
            'raw' => [],
        ], $overrides);
    }

    public function test_the_internal_endpoint_requires_the_shared_token(): void
    {
        $this->postJson('/api/internal/transactions', [])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->withHeaders(['X-Internal-Token' => 'wrong', 'Accept' => 'application/json'])
            ->postJson('/api/internal/transactions', [])
            ->assertStatus(401);
    }

    public function test_a_detected_then_confirmed_transaction_pays_the_invoice_and_credits_the_balance_once(): void
    {
        $invoice = $this->createInvoice();
        $payload = $this->payload($invoice, '100');

        // 1. detected -> invoice moves to confirming, amount sits in `pending`.
        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('invoice_id', $invoice->id);

        $this->assertSame('confirming', $invoice->refresh()->status->value);
        $this->assertSame('100.000000', $this->balance()['pending']);
        $this->assertSame('0.000000', $this->balance()['available']);

        // 2. confirmed -> invoice paid, funds move from pending to available.
        $confirmed = $this->payload($invoice, '100', 'confirmed', ['tx_hash' => $payload['tx_hash']]);

        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $confirmed)
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status->value);
        $this->assertTrue($invoice->isPaid());
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame('100.000000', $this->balance()['available']);
        $this->assertSame('0.000000', $this->balance()['pending']);
        $this->assertSame(1, LedgerEntry::count());

        // 3. the identical confirmed payload replayed must not credit twice.
        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $confirmed)
            ->assertOk();

        $this->assertSame('100.000000', $this->balance()['available']);
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(1, Transaction::count());
    }

    public function test_an_underpayment_within_the_merchant_tolerance_is_treated_as_paid(): void
    {
        $invoice = $this->createInvoice();

        // Default tolerance is 0.5%, so 99.6 of 100 still counts as paid.
        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $this->payload($invoice, '99.6', 'confirmed'))
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status->value);
        $this->assertSame('99.600000', Money::format($invoice->amount_confirmed, 6));
    }

    public function test_an_underpayment_beyond_the_tolerance_stays_confirming(): void
    {
        $invoice = $this->createInvoice();

        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $this->payload($invoice, '90', 'confirmed'))
            ->assertOk();

        $this->assertSame('confirming', $invoice->refresh()->status->value);
        // The money is still credited: it arrived on chain.
        $this->assertSame('90.000000', $this->balance()['available']);
    }

    public function test_paying_more_than_the_invoice_amount_marks_it_overpaid(): void
    {
        $invoice = $this->createInvoice();

        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $this->payload($invoice, '120', 'confirmed'))
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('overpaid', $invoice->status->value);
        $this->assertTrue($invoice->status->isPaid());
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame('120.000000', $this->balance()['available']);
    }

    public function test_an_orphaned_transaction_reverses_a_credit(): void
    {
        $invoice = $this->createInvoice();
        $confirmed = $this->payload($invoice, '100', 'confirmed');

        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $confirmed)->assertOk();

        $this->assertSame('100.000000', $this->balance()['available']);

        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', array_merge($confirmed, ['status' => 'orphaned']))
            ->assertOk();

        $this->assertSame('0.000000', $this->balance()['available']);
        $this->assertSame(2, LedgerEntry::count());
        $this->assertNull(Transaction::first()->credited_at);
        $this->assertSame('pending', $invoice->refresh()->status->value);
    }

    public function test_a_payment_to_an_unknown_address_is_acknowledged_and_ignored(): void
    {
        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', [
                'network' => 'tron',
                'tx_hash' => 'tx-unknown',
                'symbol' => 'USDT',
                'to_address' => 'TNotOneOfOurs00000000000000000000',
                'amount' => '5',
                'status' => 'confirmed',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('transaction_id', null);

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Balance::count());
    }

    public function test_evm_addresses_match_case_insensitively(): void
    {
        $invoice = $this->createInvoice(['network' => 'ethereum', 'currency' => 'USDT']);

        $payload = $this->payload($invoice, '100', 'confirmed', [
            'to_address' => mb_strtoupper($invoice->depositAddress->address),
            'contract_address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
        ]);

        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $payload)
            ->assertOk()
            ->assertJsonPath('invoice_id', $invoice->id);

        $this->assertSame('paid', $invoice->refresh()->status->value);
    }

    public function test_the_batch_endpoint_ingests_several_transactions(): void
    {
        $a = $this->createInvoice();
        $b = $this->createInvoice(['amount' => '50']);

        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions/batch', [
                'transactions' => [
                    $this->payload($a, '100', 'confirmed'),
                    $this->payload($b, '50', 'confirmed'),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'results');

        $this->assertSame('paid', $a->refresh()->status->value);
        $this->assertSame('paid', $b->refresh()->status->value);
        $this->assertSame('150.000000', $this->balance()['available']);
    }

    public function test_the_heartbeat_updates_network_scan_state(): void
    {
        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/heartbeat', [
                'network' => 'bsc',
                'last_scanned_block' => 4242,
                'head_block' => 4250,
                'healthy' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('networks', [
            'code' => 'bsc', 'last_scanned_block' => 4242, 'watcher_healthy' => true,
        ]);
    }

    public function test_watch_addresses_lists_active_addresses_for_a_network(): void
    {
        $invoice = $this->createInvoice();

        $this->withHeaders($this->internalHeaders())
            ->getJson('/api/internal/watch-addresses?network=tron')
            ->assertOk()
            ->assertJsonCount(1, 'addresses')
            ->assertJsonPath('addresses.0.address', $invoice->depositAddress->address);
    }

    public function test_internal_config_exposes_networks_and_contracts(): void
    {
        $this->withHeaders($this->internalHeaders())
            ->getJson('/api/internal/config')
            ->assertOk()
            ->assertJsonCount(3, 'networks')
            ->assertJsonPath('networks.0.code', 'bsc')
            ->assertJsonPath('networks.0.confirmations_required', 15)
            ->assertJsonCount(2, 'networks.0.tokens');
    }

    /** @return array{available: string, pending: string} */
    private function balance(string $currency = 'USDT', string $network = 'tron'): array
    {
        $balance = Balance::where('merchant_id', $this->merchant->id)
            ->where('currency', $currency)
            ->where('network_code', $network)
            ->firstOrFail();

        return [
            'available' => Money::format($balance->available, 6),
            'pending' => Money::format($balance->pending, 6),
        ];
    }
}
