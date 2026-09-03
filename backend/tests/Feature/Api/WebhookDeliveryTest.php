<?php

namespace Tests\Feature\Api;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
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

    private function paidInvoice(): Invoice
    {
        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', ['amount' => '100', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()->json('data.id');

        $invoice = Invoice::with('depositAddress')->findOrFail($id);

        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', [
            'network' => 'tron',
            'tx_hash' => $this->txHash('webhook-test'),
            'log_index' => 0,
            'symbol' => 'USDT',
            'to_address' => $invoice->depositAddress->address,
            'amount' => '100',
            'amount_raw' => '100000000',
            'confirmations' => 19,
            'status' => 'confirmed',
        ])->assertOk();

        return $invoice->refresh();
    }

    public function test_the_delivery_carries_the_spec_headers_and_a_verifiable_signature(): void
    {
        $invoice = $this->paidInvoice();

        $delivery = WebhookDelivery::where('invoice_id', $invoice->id)
            ->where('event', 'invoice.paid')->firstOrFail();

        $this->assertSame('delivered', $delivery->status->value);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->response_code);

        $sent = null;
        Http::recorded(function (ClientRequest $request) use (&$sent, $delivery) {
            if ($request->url() === 'https://merchant.test/hooks'
                && $request->header('X-CryptoPay-Delivery')[0] === $delivery->id) {
                $sent = $request;
            }
        });

        $this->assertNotNull($sent, 'The webhook was never sent.');

        $this->assertSame('invoice.paid', $sent->header('X-CryptoPay-Event')[0]);
        $this->assertSame($delivery->id, $sent->header('X-CryptoPay-Delivery')[0]);

        $timestamp = $sent->header('X-CryptoPay-Timestamp')[0];
        $signature = $sent->header('X-CryptoPay-Signature')[0];
        $body = $sent->body();

        // Exactly what a merchant would compute: hmac over "<timestamp>.<raw body>".
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->merchant->webhook_secret);

        $this->assertSame($expected, $signature);
        $this->assertSame($expected, WebhookService::sign($this->merchant->webhook_secret, (int) $timestamp, $body));
        $this->assertStringStartsWith('sha256=', $signature);

        // The stored payload is byte-identical to what was signed and sent.
        $this->assertSame($delivery->body(), $body);

        $decoded = json_decode($body, true);
        $this->assertSame($delivery->id, $decoded['id']);
        $this->assertSame('invoice.paid', $decoded['event']);
        $this->assertSame($invoice->id, $decoded['data']['invoice']['id']);
        $this->assertSame('paid', $decoded['data']['invoice']['status']);
        $this->assertTrue($decoded['data']['invoice']['is_paid']);
        $this->assertNull($decoded['data']['token_purchase']);
    }

    public function test_a_merchant_without_a_webhook_url_gets_no_deliveries(): void
    {
        [, $key] = $this->makeMerchant(['webhook_url' => null]);

        $id = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '1', 'currency' => 'USDT', 'network' => 'tron'])
            ->json('data.id');

        $this->withHeaders($this->keyHeaders($key))->postJson("/api/v1/invoices/{$id}/cancel")->assertOk();

        $this->assertSame(0, WebhookDelivery::where('invoice_id', $id)->count());
    }

    public function test_a_failing_endpoint_is_retried_on_the_spec_backoff_schedule(): void
    {
        // Freeze on a whole second: next_attempt_at is persisted without
        // microseconds, so a fractional "now" makes the 60s assertion flaky.
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 12, 0, 0));
        $this->resetHttpFakes();
        Http::fake([
            '*/addresses/derive' => Http::response(['address' => 'TFakeAddress000000000000000000000']),
            'merchant.test/*' => Http::response(['nope' => true], 500),
        ]);

        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', ['amount' => '1', 'currency' => 'USDT', 'network' => 'tron'])
            ->json('data.id');

        $this->withHeaders($this->keyHeaders($this->key))->postJson("/api/v1/invoices/{$id}/cancel")->assertOk();

        $delivery = WebhookDelivery::where('invoice_id', $id)->firstOrFail();
        $this->assertSame('pending', $delivery->status->value);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(500, $delivery->response_code);
        // First retry is one minute out.
        $this->assertSame(60, (int) round(now()->diffInSeconds($delivery->next_attempt_at)));

        // Not yet due: webhooks:retry leaves it alone.
        $this->artisan('webhooks:retry')->assertSuccessful();
        $this->assertSame(1, $delivery->refresh()->attempts);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('webhooks:retry')->assertSuccessful();
        $this->assertSame(2, $delivery->refresh()->attempts);
        $this->assertSame(300, (int) round(now()->diffInSeconds($delivery->next_attempt_at)));

        Carbon::setTestNow();
    }

    public function test_a_delivery_is_marked_failed_once_the_attempts_are_exhausted(): void
    {
        $this->resetHttpFakes();
        Http::fake(['*' => Http::response('server exploded', 500)]);

        $delivery = WebhookDelivery::create([
            'merchant_id' => $this->merchant->id,
            'event' => 'invoice.paid',
            'url' => 'https://merchant.test/hooks',
            'payload' => '{"id":"x"}',
            'attempts' => 5,
            'max_attempts' => 6,
            'status' => 'pending',
            'next_attempt_at' => now()->subMinute(),
        ]);

        $this->artisan('webhooks:retry')->assertSuccessful();

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status->value);
        $this->assertSame(6, $delivery->attempts);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame('server exploded', $delivery->response_body);
    }

    public function test_an_admin_can_retry_a_delivery_by_hand(): void
    {
        $invoice = $this->paidInvoice();
        $delivery = WebhookDelivery::where('invoice_id', $invoice->id)->firstOrFail();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/webhooks/{$delivery->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->assertSame(2, $delivery->refresh()->attempts);
    }
}
