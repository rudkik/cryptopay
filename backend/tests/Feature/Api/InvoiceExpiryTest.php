<?php

namespace Tests\Feature\Api;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceExpiryTest extends TestCase
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
                'amount' => '100', 'currency' => 'USDT', 'network' => 'tron', 'expires_in' => 3600,
            ], $overrides))
            ->assertCreated()->json('data.id');

        return Invoice::with('depositAddress')->findOrFail($id);
    }

    private function ingest(Invoice $invoice, string $amount, string $status): void
    {
        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', [
            'network' => $invoice->network_code,
            'tx_hash' => 'tx-'.substr(md5($invoice->id.$amount.$status), 0, 24),
            'log_index' => 0,
            'symbol' => $invoice->currency,
            'to_address' => $invoice->depositAddress->address,
            'amount' => $amount,
            'amount_raw' => bcmul($amount, '1000000', 0),
            'confirmations' => $status === 'confirmed' ? 19 : 1,
            'status' => $status,
        ])->assertOk();
    }

    public function test_an_untouched_invoice_expires_and_fires_a_webhook(): void
    {
        $invoice = $this->createInvoice();

        Carbon::setTestNow(now()->addHours(2));

        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('expired', $invoice->refresh()->status->value);
        $this->assertDatabaseHas('webhook_deliveries', [
            'invoice_id' => $invoice->id, 'event' => 'invoice.expired',
        ]);

        Carbon::setTestNow();
    }

    public function test_a_partially_confirmed_invoice_becomes_partially_paid_on_expiry(): void
    {
        $invoice = $this->createInvoice();

        $this->ingest($invoice, '40', 'confirmed');
        $this->assertSame('confirming', $invoice->refresh()->status->value);

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('partially_paid', $invoice->refresh()->status->value);
        $this->assertDatabaseHas('webhook_deliveries', [
            'invoice_id' => $invoice->id, 'event' => 'invoice.partially_paid',
        ]);

        Carbon::setTestNow();
    }

    public function test_a_late_payment_to_an_expired_invoice_is_still_credited_and_marks_it_paid(): void
    {
        $invoice = $this->createInvoice();

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('invoices:expire')->assertSuccessful();
        $this->assertSame('expired', $invoice->refresh()->status->value);

        $this->ingest($invoice, '100', 'confirmed');

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status->value);
        $this->assertNotNull($invoice->paid_at);

        $this->assertDatabaseHas('balances', [
            'merchant_id' => $this->merchant->id, 'currency' => 'USDT', 'network_code' => 'tron',
        ]);
        $this->assertSame(1, LedgerEntry::count());

        $this->assertTrue(WebhookDelivery::where('invoice_id', $invoice->id)
            ->where('event', 'invoice.paid')->exists());

        Carbon::setTestNow();
    }

    public function test_a_late_payment_to_a_cancelled_invoice_is_credited_too(): void
    {
        $invoice = $this->createInvoice();

        $this->withHeaders($this->keyHeaders($this->key))
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel")->assertOk();

        $this->ingest($invoice, '100', 'confirmed');

        $this->assertSame('paid', $invoice->refresh()->status->value);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_the_confirming_transition_fires_its_own_webhook(): void
    {
        $invoice = $this->createInvoice();

        $this->ingest($invoice, '100', 'detected');

        $this->assertDatabaseHas('webhook_deliveries', [
            'invoice_id' => $invoice->id, 'event' => 'invoice.confirming',
        ]);
    }

    public function test_networks_health_marks_a_stale_watcher_unhealthy(): void
    {
        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/heartbeat', [
            'network' => 'tron', 'last_scanned_block' => 10, 'healthy' => true,
        ])->assertOk();

        $this->assertDatabaseHas('networks', ['code' => 'tron', 'watcher_healthy' => true]);

        Carbon::setTestNow(now()->addMinutes(5));
        $this->artisan('networks:health')->assertSuccessful();

        $this->assertDatabaseHas('networks', ['code' => 'tron', 'watcher_healthy' => false]);

        Carbon::setTestNow();
    }
}
