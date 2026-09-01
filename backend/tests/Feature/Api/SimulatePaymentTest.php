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

class SimulatePaymentTest extends TestCase
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

    private function invoice(array $overrides = []): Invoice
    {
        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', array_merge([
                'amount' => '100', 'currency' => 'USDT', 'network' => 'tron',
            ], $overrides))
            ->assertCreated()->json('data.id');

        return Invoice::with('depositAddress')->findOrFail($id);
    }

    public function test_a_confirmed_simulation_pays_the_invoice_through_the_real_pipeline(): void
    {
        $invoice = $this->invoice();

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment", ['confirmed' => true])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('invoice.status', 'paid');

        $payload = $response->json('payload');

        $this->assertStringStartsWith('0xsim', $payload['tx_hash']);
        $this->assertSame($invoice->depositAddress->address, $payload['to_address']);
        $this->assertSame('confirmed', $payload['status']);
        // confirmations must clear the network requirement (19 for tron).
        $this->assertGreaterThanOrEqual(19, $payload['confirmations']);
        $this->assertSame('100000000', $payload['amount_raw']);

        $balance = Balance::where('merchant_id', $this->merchant->id)->firstOrFail();
        $this->assertSame('100.000000', Money::format($balance->available, 6));
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_an_unconfirmed_simulation_only_moves_the_invoice_to_confirming(): void
    {
        $invoice = $this->invoice();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment", ['confirmed' => false])
            ->assertOk()
            ->assertJsonPath('invoice.status', 'confirming');

        $payload = Transaction::firstOrFail();
        $this->assertSame('detected', $payload->status->value);
        $this->assertSame(1, $payload->confirmations);

        $balance = Balance::where('merchant_id', $this->merchant->id)->firstOrFail();
        $this->assertSame('0.000000', Money::format($balance->available, 6));
        $this->assertSame('100.000000', Money::format($balance->pending, 6));
    }

    public function test_a_partial_simulated_amount_is_supported(): void
    {
        $invoice = $this->invoice();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment", ['amount' => '30', 'confirmed' => true])
            ->assertOk()
            ->assertJsonPath('invoice.status', 'confirming')
            ->assertJsonPath('invoice.amount_confirmed', '30.000000');
    }

    public function test_simulation_is_refused_when_the_feature_flag_is_off(): void
    {
        config()->set('services.simulation.enabled', false);

        $invoice = $this->invoice();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment", [])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertSame(0, Transaction::count());
    }
}
