<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Balance;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\WebhookDelivery;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPEC §6.2 `invoice.reversed`: a reorg (or a failed transaction) takes back
 * money an invoice had already settled with. The merchant was told the invoice
 * was paid, so it has to be told when that stops being true — silently
 * dropping the invoice back to `pending` left whatever it credited standing.
 */
class InvoiceReversalTest extends TestCase
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
            'tx_hash' => $this->txHash($invoice->id.$amount.$seed, $invoice->network_code),
            'log_index' => 0,
            'symbol' => $invoice->currency,
            'from_address' => 'TFromAddress0000000000000000000000',
            'to_address' => $invoice->depositAddress->address,
            'amount' => $amount,
            'amount_raw' => bcmul($amount, '1000000', 0),
            'block_number' => 1001,
            'confirmations' => $status === 'confirmed' ? 19 : 1,
            'status' => $status,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function ingest(array $payload): void
    {
        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', $payload)
            ->assertOk();
    }

    private function deliveries(string $event): int
    {
        return WebhookDelivery::where('merchant_id', $this->merchant->id)->where('event', $event)->count();
    }

    /** The merchant's available balance, formatted the way the API prints it. */
    private function available(): string
    {
        return Money::format(Balance::where('merchant_id', $this->merchant->id)->firstOrFail()->available, 6);
    }

    public function test_orphaning_a_confirmed_payment_reverses_the_invoice_and_emits_one_reversed_webhook(): void
    {
        $invoice = $this->createInvoice();
        $confirmed = $this->payload($invoice, '100', 'confirmed');

        $this->ingest($confirmed);

        $this->assertSame('paid', $invoice->refresh()->status->value);
        $this->assertSame(1, $this->deliveries('invoice.paid'));

        $this->ingest(array_merge($confirmed, ['status' => 'orphaned']));

        // The invoice falls back to its pre-payment state and the credit is gone.
        $invoice->refresh();
        $this->assertSame('pending', $invoice->status->value);
        $this->assertNull($invoice->paid_at);
        $this->assertSame('0.000000', $this->available());
        $this->assertSame(2, LedgerEntry::count());

        $this->assertSame(1, $this->deliveries('invoice.reversed'));

        $delivery = WebhookDelivery::where('event', 'invoice.reversed')->firstOrFail();
        $body = json_decode($delivery->payload, true);

        $this->assertSame('invoice.reversed', $body['event']);
        $this->assertSame($invoice->id, $delivery->invoice_id);
        $this->assertSame('pending', $body['data']['invoice']['status']);
        $this->assertFalse($body['data']['invoice']['is_paid']);
        $this->assertSame([
            'transaction_id' => $invoice->transactions()->firstOrFail()->id,
            'tx_hash' => $confirmed['tx_hash'],
            'amount' => '100.000000',
            'reason' => 'orphaned',
        ], $body['data']['reversal']);

        // The status event that would otherwise accompany the transition is
        // deliberately suppressed: `invoice.reversed` already carries the
        // fresh invoice, and a bare status event reads like new activity.
        $this->assertSame(0, $this->deliveries('invoice.confirming'));

        $audit = AuditLog::where('action', 'invoice.reversed')->firstOrFail();
        $this->assertSame($invoice->id, $audit->subject_id);
        $this->assertSame('orphaned', $audit->changes['reversal']['reason']);
        $this->assertSame(['from' => 'paid', 'to' => 'pending'], $audit->changes['status']);
    }

    public function test_a_re_confirmation_after_a_reversal_pays_the_invoice_again(): void
    {
        $invoice = $this->createInvoice();
        $confirmed = $this->payload($invoice, '100', 'confirmed');

        $this->ingest($confirmed);
        $this->ingest(array_merge($confirmed, ['status' => 'orphaned']));
        $this->ingest($confirmed);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status->value);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame('100.000000', $this->available());

        $this->assertSame(2, $this->deliveries('invoice.paid'));
        $this->assertSame(1, $this->deliveries('invoice.reversed'));
    }

    public function test_a_replayed_orphan_payload_does_not_emit_a_second_reversed(): void
    {
        $invoice = $this->createInvoice();
        $confirmed = $this->payload($invoice, '100', 'confirmed');
        $orphaned = array_merge($confirmed, ['status' => 'orphaned']);

        $this->ingest($confirmed);
        $this->ingest($orphaned);
        $this->ingest($orphaned);

        $this->assertSame(1, $this->deliveries('invoice.reversed'));
        $this->assertSame(1, AuditLog::where('action', 'invoice.reversed')->count());
        $this->assertSame('0.000000', $this->available());
    }

    public function test_a_failed_transaction_reverses_with_reason_failed(): void
    {
        $invoice = $this->createInvoice();
        $confirmed = $this->payload($invoice, '100', 'confirmed');

        $this->ingest($confirmed);
        $this->ingest(array_merge($confirmed, ['status' => 'failed']));

        $this->assertSame('pending', $invoice->refresh()->status->value);

        $body = json_decode(WebhookDelivery::where('event', 'invoice.reversed')->firstOrFail()->payload, true);

        $this->assertSame('failed', $body['data']['reversal']['reason']);
    }

    /**
     * Losing one of two payments leaves the invoice short rather than empty:
     * it is no longer paid, so the merchant still has to be told.
     */
    public function test_losing_one_of_two_payments_reverses_the_invoice_to_confirming(): void
    {
        $invoice = $this->createInvoice();
        $first = $this->payload($invoice, '50', 'confirmed', 'a');
        $second = $this->payload($invoice, '50', 'confirmed', 'b');

        $this->ingest($first);
        $this->ingest($second);

        $this->assertSame('paid', $invoice->refresh()->status->value);

        // The first (partial) payment already announced `confirming`; the
        // reversal must not announce it a second time.
        $confirmingBefore = $this->deliveries('invoice.confirming');
        $this->assertSame(1, $confirmingBefore);

        $this->ingest(array_merge($second, ['status' => 'orphaned']));

        $invoice->refresh();
        $this->assertSame('confirming', $invoice->status->value);
        $this->assertSame('50.000000', Money::format($invoice->amount_confirmed, 6));
        $this->assertSame('50.000000', $this->available());

        $this->assertSame(1, $this->deliveries('invoice.reversed'));
        $this->assertSame($confirmingBefore, $this->deliveries('invoice.confirming'));

        $body = json_decode(WebhookDelivery::where('event', 'invoice.reversed')->firstOrFail()->payload, true);

        $this->assertSame('50.000000', $body['data']['reversal']['amount']);
        $this->assertSame('confirming', $body['data']['invoice']['status']);
    }

    /**
     * A transaction that never confirmed was never part of the invoice's
     * confirmed total, so losing it is not a reversal — the invoice simply
     * goes back to waiting.
     */
    public function test_orphaning_a_merely_detected_transaction_emits_no_reversed_webhook(): void
    {
        $invoice = $this->createInvoice();
        $detected = $this->payload($invoice, '100', 'detected');

        $this->ingest($detected);
        $this->assertSame('confirming', $invoice->refresh()->status->value);
        $this->assertSame(1, $this->deliveries('invoice.confirming'));

        $this->ingest(array_merge($detected, ['status' => 'orphaned']));

        $this->assertSame('pending', $invoice->refresh()->status->value);
        $this->assertSame(0, $this->deliveries('invoice.reversed'));
        $this->assertSame(0, AuditLog::where('action', 'invoice.reversed')->count());
    }

    /**
     * An overpaid invoice that loses the surplus is still paid: nothing is
     * reversed as far as the merchant's fulfilment decision is concerned.
     */
    public function test_losing_a_surplus_payment_is_not_a_reversal(): void
    {
        $invoice = $this->createInvoice();
        $first = $this->payload($invoice, '100', 'confirmed', 'a');
        $surplus = $this->payload($invoice, '40', 'confirmed', 'b');

        $this->ingest($first);
        $this->ingest($surplus);

        $this->assertSame('overpaid', $invoice->refresh()->status->value);

        $this->ingest(array_merge($surplus, ['status' => 'orphaned']));

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status->value);
        $this->assertTrue($invoice->isPaid());
        $this->assertSame(0, $this->deliveries('invoice.reversed'));
        $this->assertSame(2, $this->deliveries('invoice.paid'));
    }
}
