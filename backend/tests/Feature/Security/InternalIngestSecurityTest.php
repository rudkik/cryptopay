<?php

namespace Tests\Feature\Security;

use App\Http\Requests\Internal\StoreTransactionBatchRequest;
use App\Models\Balance;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\TokenContract;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The internal ingest endpoint is the only path that turns into merchant money.
 * These cover its payload validation and the replay invariants around it.
 */
class InternalIngestSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const HEX64 = '4bf5122f344554c53bde2ebb8cd2b7e3d1600ad631c385a5d7cce23c7785459a';

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
                'amount' => '100', 'currency' => 'USDT', 'network' => 'tron',
            ], $overrides))
            ->assertCreated()
            ->json('data.id');

        return Invoice::with('depositAddress')->findOrFail($id);
    }

    private function payload(Invoice $invoice, array $overrides = []): array
    {
        return array_merge([
            'network' => $invoice->network_code,
            'tx_hash' => $this->txHash($invoice->id, $invoice->network_code),
            'log_index' => 0,
            'symbol' => $invoice->currency,
            'to_address' => $invoice->depositAddress->address,
            'amount' => '100',
            'amount_raw' => '100000000',
            'block_number' => 1001,
            'confirmations' => 19,
            'status' => 'confirmed',
        ], $overrides);
    }

    private function ingest(array $payload): TestResponse
    {
        return $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $payload);
    }

    public function test_a_zero_amount_is_rejected(): void
    {
        $invoice = $this->createInvoice();

        $this->assertInvalidField(
            $this->ingest($this->payload($invoice, ['amount' => '0', 'amount_raw' => '0'])),
            'amount',
        );

        $this->assertSame(0, Transaction::count());
    }

    public function test_amount_and_amount_raw_must_agree_at_the_contract_decimals(): void
    {
        $invoice = $this->createInvoice();

        // "100" at tron/USDT's 6 decimals is 100000000, not 1e20.
        $this->assertInvalidField(
            $this->ingest($this->payload($invoice, ['amount_raw' => '100000000000000000000'])),
            'amount_raw',
        );

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Balance::count());
    }

    public function test_a_negative_amount_raw_is_rejected(): void
    {
        $invoice = $this->createInvoice();

        $this->assertInvalidField(
            $this->ingest($this->payload($invoice, ['amount' => null, 'amount_raw' => '-100000000'])),
            'amount_raw',
        );
    }

    public function test_the_symbol_must_be_an_enabled_contract_on_that_network(): void
    {
        $invoice = $this->createInvoice();

        $this->assertInvalidField(
            $this->ingest($this->payload($invoice, ['symbol' => 'SCAM'])),
            'symbol',
        );

        TokenContract::query()
            ->where('network_code', 'tron')->where('symbol', 'USDC')
            ->update(['is_enabled' => false]);

        $this->assertInvalidField(
            $this->ingest($this->payload($invoice, ['symbol' => 'USDC'])),
            'symbol',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badHashes(): array
    {
        return [
            'tron hash carrying an 0x prefix' => ['tron', '0x'.self::HEX64],
            'tron hash too short' => ['tron', 'abc123'],
            'tron hash with non-hex characters' => ['tron', str_repeat('z', 64)],
            'evm hash without the 0x prefix' => ['ethereum', self::HEX64],
            'evm hash too short' => ['ethereum', '0xdeadbeef'],
        ];
    }

    #[DataProvider('badHashes')]
    public function test_the_tx_hash_must_match_the_network_format(string $network, string $hash): void
    {
        $invoice = $this->createInvoice(['network' => $network, 'currency' => 'USDT']);

        $this->assertInvalidField(
            $this->ingest($this->payload($invoice, ['tx_hash' => $hash])),
            'tx_hash',
        );
    }

    public function test_absurd_confirmations_and_negative_blocks_are_rejected(): void
    {
        $invoice = $this->createInvoice();

        $this->assertInvalidField(
            $this->ingest($this->payload($invoice, ['confirmations' => 999_999_999])),
            'confirmations',
        );

        $this->assertInvalidField(
            $this->ingest($this->payload($invoice, ['block_number' => -5])),
            'block_number',
        );
    }

    public function test_the_batch_endpoint_is_bounded(): void
    {
        $invoice = $this->createInvoice();

        $items = array_fill(0, StoreTransactionBatchRequest::MAX_ITEMS + 1, $this->payload($invoice));

        $this->assertInvalidField(
            $this->withHeaders($this->internalHeaders())
                ->postJson('/api/internal/transactions/batch', ['transactions' => $items]),
            'transactions',
        );
    }

    public function test_the_batch_endpoint_validates_every_item(): void
    {
        $invoice = $this->createInvoice();

        $this->assertInvalidField(
            $this->withHeaders($this->internalHeaders())
                ->postJson('/api/internal/transactions/batch', [
                    'transactions' => [
                        $this->payload($invoice),
                        $this->payload($invoice, [
                            'tx_hash' => $this->txHash('second'),
                            'amount_raw' => '999999999999',
                        ]),
                    ],
                ]),
            'transactions.1.amount_raw',
        );

        $this->assertSame(0, Transaction::count(), 'a rejected batch must not partially apply');
    }

    public function test_an_oversized_body_is_refused_before_validation(): void
    {
        $invoice = $this->createInvoice();

        $this->ingest($this->payload($invoice, [
            'raw' => ['blob' => str_repeat('a', 2_000_000)],
        ]))->assertStatus(413);

        $this->assertSame(0, Transaction::count());
    }

    public function test_an_oversized_raw_blob_is_rejected(): void
    {
        $invoice = $this->createInvoice();

        $this->assertInvalidField(
            $this->ingest($this->payload($invoice, ['raw' => ['blob' => str_repeat('a', 20_000)]])),
            'raw',
        );
    }

    /**
     * The core replay invariant: a confirmed transaction has already moved
     * money, so a later payload for the same (network, tx_hash, log_index) may
     * not restate what that money was.
     */
    public function test_a_replay_cannot_rewrite_the_amount_of_a_credited_transaction(): void
    {
        $invoice = $this->createInvoice();

        $this->ingest($this->payload($invoice))->assertOk();

        $this->assertSame(0, Money::cmp(Balance::firstOrFail()->available, '100'));

        // The same transaction, with a wildly different (internally consistent) amount.
        $this->ingest($this->payload($invoice, [
            'amount' => '1000000',
            'amount_raw' => '1000000000000',
        ]))->assertOk();

        $this->assertSame(
            0,
            Money::cmp(Transaction::firstOrFail()->amount, '100'),
            'the settled amount was rewritten by a replay',
        );
        $this->assertSame(0, Money::cmp(Balance::firstOrFail()->available, '100'));
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(0, Money::cmp($invoice->refresh()->amount_confirmed, '100'));
    }

    public function test_an_inflated_orphan_cannot_drive_the_balance_negative(): void
    {
        $invoice = $this->createInvoice();

        $this->ingest($this->payload($invoice))->assertOk();

        // TransactionIngestService clamps a negative balance to zero as a last
        // resort. That fallback must not be what saves us here — the frozen
        // amount and the ledger-derived reversal have to get it right on their
        // own — so the clamp's `critical` log is asserted never to fire.
        Log::spy();

        $this->ingest($this->payload($invoice, [
            'status' => 'orphaned',
            'amount' => '1000000',
            'amount_raw' => '1000000000000',
        ]))->assertOk();

        Log::shouldNotHaveReceived('critical');

        $this->assertSame(
            0,
            Money::cmp(Balance::firstOrFail()->available, '0'),
            'the reversal took out more than was credited',
        );
        $this->assertNull(Transaction::firstOrFail()->credited_at);

        // Deposit +100 and adjustment -100, nothing larger.
        $this->assertSame(0, Money::cmp((string) LedgerEntry::sum('amount'), '0'));
        $this->assertSame(
            0,
            Money::cmp((string) LedgerEntry::where('type', 'adjustment')->value('amount'), '-100'),
        );
    }

    public function test_a_confirmed_transaction_is_never_downgraded_to_detected(): void
    {
        $invoice = $this->createInvoice();

        $this->ingest($this->payload($invoice))->assertOk();
        $this->ingest($this->payload($invoice, ['status' => 'detected', 'confirmations' => 1]))->assertOk();

        $transaction = Transaction::firstOrFail();

        $this->assertSame('confirmed', $transaction->status->value);
        $this->assertSame(19, $transaction->confirmations);
        $this->assertSame('paid', $invoice->refresh()->status->value);
    }

    /**
     * A USDC transfer to a USDT invoice's address is real money and is credited
     * to the merchant's USDC balance, but it does not pay a USDT invoice.
     */
    public function test_a_transaction_in_another_currency_does_not_pay_the_invoice(): void
    {
        $invoice = $this->createInvoice(['currency' => 'USDT']);

        $this->ingest($this->payload($invoice, [
            'symbol' => 'USDC',
            'contract_address' => 'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8',
        ]))->assertOk();

        $invoice->refresh();

        $this->assertSame('pending', $invoice->status->value, 'a USDC transfer settled a USDT invoice');
        $this->assertSame(0, Money::cmp($invoice->amount_confirmed, '0'));
        $this->assertNull($invoice->paid_at);

        // The money itself is not lost: it lands on the USDC balance.
        $usdc = Balance::where('currency', 'USDC')->firstOrFail();
        $this->assertSame(0, Money::cmp($usdc->available, '100'));
        $this->assertSame(0, Balance::where('currency', 'USDT')->count());

        // And it is still recorded against the invoice, for support.
        $this->assertSame('USDC', Transaction::firstOrFail()->currency);
    }

    public function test_a_matching_currency_still_pays_the_invoice(): void
    {
        $invoice = $this->createInvoice(['currency' => 'USDT']);

        $this->ingest($this->payload($invoice))->assertOk();

        $this->assertSame('paid', $invoice->refresh()->status->value);
    }
}
