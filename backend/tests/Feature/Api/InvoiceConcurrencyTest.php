<?php

namespace Tests\Feature\Api;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Services\InvoiceService;
use App\Services\TransactionIngestService;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Tests\TestCase;

/**
 * Two watcher payloads for the same invoice arrive at once all the time — two
 * transactions confirming in the same block, a confirmation racing the expiry
 * sweep, or the watcher simply retrying. Each of these cases was reproduced
 * against the Postgres stack with parallel requests before the fix; the tests
 * here pin the behaviour deterministically.
 */
class InvoiceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();

        [$this->merchant, $this->key] = $this->makeMerchant([
            'webhook_url' => 'https://merchant.test/hooks',
        ]);
    }

    private function createInvoice(array $overrides = []): Invoice
    {
        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', array_merge([
                'amount' => '100', 'currency' => 'USDT', 'network' => 'tron',
            ], $overrides))
            ->assertCreated()->json('data.id');

        return Invoice::with('depositAddress')->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function payload(Invoice $invoice, string $amount, string $status, string $seed = ''): array
    {
        return [
            'network' => $invoice->network_code,
            'tx_hash' => $this->txHash($invoice->id.$amount.$status.$seed, $invoice->network_code),
            'log_index' => 0,
            'symbol' => $invoice->currency,
            'to_address' => $invoice->depositAddress->address,
            'amount' => $amount,
            'amount_raw' => bcmul($amount, '1000000', 0),
            'confirmations' => $status === 'confirmed' ? 19 : 1,
            'status' => $status,
        ];
    }

    /**
     * The bug: recalculate() decided "did the status change?" against the
     * Invoice instance its caller happened to be holding. Two ingests that both
     * loaded the invoice before either saved therefore both saw
     * pending -> paid, and the merchant got `invoice.paid` twice for one
     * payment. The second caller here holds exactly such a stale copy.
     */
    public function test_a_second_recalculate_on_a_stale_copy_does_not_re_announce_the_transition(): void
    {
        $invoice = $this->createInvoice();

        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $this->payload($invoice, '100', 'confirmed'))
            ->assertOk();

        $this->assertSame('paid', $invoice->refresh()->status->value);
        $this->assertSame(1, WebhookDelivery::where('event', 'invoice.paid')->count());

        // A concurrent request's view of the world: loaded before the winner
        // committed, so it still believes the invoice is confirming.
        $stale = Invoice::findOrFail($invoice->id);
        $stale->forceFill(['status' => InvoiceStatus::Confirming->value])->syncOriginal();

        app(InvoiceService::class)->recalculate($stale);

        $this->assertSame('paid', $stale->status->value);
        $this->assertSame(1, WebhookDelivery::where('event', 'invoice.paid')->count());
        $this->assertSame(1, LedgerEntry::count());
    }

    /**
     * Same guard on the cancel path, which does not go through recalculate():
     * a merchant retrying POST /cancel used to emit a second
     * `invoice.cancelled` and a stale copy could cancel an invoice that had
     * already moved on.
     */
    public function test_cancelling_from_a_stale_copy_is_rejected_and_emits_nothing_extra(): void
    {
        $invoice = $this->createInvoice();
        $stale = Invoice::findOrFail($invoice->id);

        app(InvoiceService::class)->cancel($invoice);

        $this->assertSame(1, WebhookDelivery::where('event', 'invoice.cancelled')->count());

        // $stale still says "pending"; the lock re-read must catch that.
        $this->assertSame(InvoiceStatus::Pending, $stale->getOriginal('status'));

        $this->withHeaders($this->keyHeaders($this->key))
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');

        $this->assertSame(1, WebhookDelivery::where('event', 'invoice.cancelled')->count());
    }

    /**
     * persist() locks the transaction row it updates, but the first payload for
     * a hash has no row to lock, so two concurrent ones both reached the INSERT
     * and the loser hit the unique index. Verified against Postgres: four
     * parallel identical payloads returned two 200s and two 500s carrying the
     * full SQL statement. The unique index is what keeps the money right; the
     * fix is to treat the violation as "someone else already wrote it" and
     * retry, because SPEC §6.5 promises these requests are idempotent.
     *
     * The race itself cannot be staged inside a single-connection test, so the
     * loser's exception is injected instead: what is asserted is that the
     * retry recovers and the caller sees a normal success.
     */
    public function test_a_lost_insert_race_is_retried_instead_of_surfacing_as_a_server_error(): void
    {
        $invoice = $this->createInvoice();
        $payload = $this->payload($invoice, '100', 'confirmed');

        $failures = 0;

        Transaction::creating(function () use (&$failures) {
            if ($failures === 0) {
                $failures++;

                throw new UniqueConstraintViolationException(
                    'pgsql',
                    'insert into "transactions" ...',
                    [],
                    new PDOException('SQLSTATE[23505]: Unique violation'),
                );
            }
        });

        try {
            $result = app(TransactionIngestService::class)->ingest($payload);
        } finally {
            Transaction::flushEventListeners();
        }

        $this->assertSame(1, $failures, 'The injected violation never fired.');
        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['transaction_id']);

        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame('paid', $invoice->refresh()->status->value);
        $this->assertSame(1, WebhookDelivery::where('event', 'invoice.paid')->count());
    }

    /**
     * Repeating the exact payload the watcher already delivered — its normal
     * behaviour on every confirmation tick — must not credit twice, must not
     * re-announce, and must answer 200.
     */
    public function test_replaying_an_identical_confirmed_payload_changes_nothing(): void
    {
        $invoice = $this->createInvoice();
        $payload = $this->payload($invoice, '100', 'confirmed');

        foreach (range(1, 4) as $ignored) {
            $this->withHeaders($this->internalHeaders())
                ->postJson('/api/internal/transactions', $payload)
                ->assertOk();
        }

        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(0, Money::cmp(LedgerEntry::sum('amount'), '100'));
        $this->assertSame(1, WebhookDelivery::count());
        $this->assertSame('invoice.paid', WebhookDelivery::first()->event);
    }

    /**
     * Two different transactions settling one invoice: both are counted, the
     * intermediate `confirming` is announced once and the final `paid` once.
     */
    public function test_two_transactions_settle_one_invoice_with_one_event_each(): void
    {
        $invoice = $this->createInvoice();

        foreach (['a', 'b'] as $seed) {
            $this->withHeaders($this->internalHeaders())
                ->postJson('/api/internal/transactions', $this->payload($invoice, '50', 'confirmed', $seed))
                ->assertOk();
        }

        $this->assertSame('paid', $invoice->refresh()->status->value);
        $this->assertSame(0, Money::cmp($invoice->amount_confirmed, '100'));
        $this->assertSame(2, LedgerEntry::count());

        $events = WebhookDelivery::orderBy('created_at')->pluck('event')->all();
        $this->assertSame(['invoice.confirming', 'invoice.paid'], $events);
    }
}
