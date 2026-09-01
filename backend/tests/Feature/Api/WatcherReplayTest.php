<?php

namespace Tests\Feature\Api;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The watcher re-POSTs a transaction on every confirmation change, and a manual
 * /rescan can re-announce an already confirmed transaction as `detected`.
 */
class WatcherReplayTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $key;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();

        [$this->merchant, $this->key] = $this->makeMerchant();

        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', ['amount' => '100', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()->json('data.id');

        $this->invoice = Invoice::with('depositAddress')->findOrFail($id);
    }

    private function announce(string $status, int $confirmations, string $amount = '100'): void
    {
        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', [
            'network' => 'tron',
            'tx_hash' => 'tx-replayed',
            'log_index' => 0,
            'symbol' => 'USDT',
            'to_address' => $this->invoice->depositAddress->address,
            'amount' => $amount,
            'amount_raw' => bcmul($amount, '1000000', 0),
            'confirmations' => $confirmations,
            'status' => $status,
        ])->assertOk();
    }

    public function test_confirmation_progress_updates_the_same_row(): void
    {
        $this->announce('detected', 1);
        $this->announce('detected', 7);
        $this->announce('detected', 15);

        $this->assertSame(1, Transaction::count());
        $this->assertSame(15, Transaction::firstOrFail()->confirmations);
        $this->assertSame('confirming', $this->invoice->refresh()->status->value);
    }

    public function test_a_rescan_cannot_downgrade_a_confirmed_transaction_back_to_detected(): void
    {
        $this->announce('confirmed', 19);

        $this->assertSame('paid', $this->invoice->refresh()->status->value);
        $this->assertSame(1, LedgerEntry::count());

        // A rescan re-announces the transaction from scratch.
        $this->announce('detected', 1);

        $transaction = Transaction::firstOrFail();
        $this->assertSame('confirmed', $transaction->status->value);
        $this->assertNotNull($transaction->credited_at);
        // Confirmations never go backwards either.
        $this->assertSame(19, $transaction->confirmations);

        $this->assertSame('paid', $this->invoice->refresh()->status->value);
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame('100.000000', Money::format(
            $this->merchant->balances()->firstOrFail()->available, 6
        ));
    }

    public function test_an_orphan_after_confirmation_still_reverses_the_credit(): void
    {
        $this->announce('confirmed', 19);
        $this->announce('orphaned', 0);

        $this->assertSame('orphaned', Transaction::firstOrFail()->status->value);
        $this->assertSame(2, LedgerEntry::count());
        $this->assertSame('0.000000', Money::format(
            $this->merchant->balances()->firstOrFail()->available, 6
        ));
    }

    public function test_a_reorged_transaction_that_comes_back_is_credited_again_exactly_once(): void
    {
        $this->announce('confirmed', 19);
        $this->announce('orphaned', 0);
        $this->announce('confirmed', 19);
        $this->announce('confirmed', 20);

        $this->assertSame(3, LedgerEntry::count(), 'credit, reversal, credit');
        $this->assertSame('100.000000', Money::format(
            $this->merchant->balances()->firstOrFail()->available, 6
        ));
        $this->assertSame('paid', $this->invoice->refresh()->status->value);
    }
}
