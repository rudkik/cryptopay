<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Balance;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();

        [, $this->key] = $this->makeMerchant();
    }

    private function invoice(): Invoice
    {
        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', ['amount' => '100', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()->json('data.id');

        return Invoice::with('depositAddress')->findOrFail($id);
    }

    private function adminBearer(): string
    {
        return User::factory()->create(['role' => UserRole::Admin->value])
            ->createToken('t')->plainTextToken;
    }

    /**
     * SIMULATION_ENABLED=true is what .env.example ships. A deployment that
     * inherits it must still not be able to mint a confirmed credit.
     */
    public function test_simulation_is_refused_in_production_even_with_the_flag_on(): void
    {
        $invoice = $this->invoice();
        $token = $this->adminBearer();

        config()->set('services.simulation.enabled', true);
        $this->app->detectEnvironment(fn () => 'production');

        $this->withToken($token)
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment", ['confirmed' => true])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Balance::count());
        $this->assertSame('pending', $invoice->refresh()->status->value);
    }

    public function test_simulation_is_refused_when_the_flag_is_off(): void
    {
        $invoice = $this->invoice();

        config()->set('services.simulation.enabled', false);

        $this->withToken($this->adminBearer())
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment")
            ->assertStatus(403);
    }

    public function test_simulation_still_works_in_the_testing_environment(): void
    {
        $invoice = $this->invoice();

        $this->withToken($this->adminBearer())
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment", ['confirmed' => true])
            ->assertOk()
            ->assertJsonPath('invoice.status', 'paid');
    }

    public function test_the_simulator_produces_a_hash_the_ingest_endpoint_would_accept(): void
    {
        $invoice = $this->invoice();

        $payload = $this->withToken($this->adminBearer())
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment", ['confirmed' => true])
            ->assertOk()
            ->json('payload');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['tx_hash']);
        $this->assertStringStartsWith(PaymentSimulator::MARKER, $payload['tx_hash']);
    }

    public function test_a_viewer_cannot_simulate(): void
    {
        $invoice = $this->invoice();

        $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

        $this->withToken($viewer->createToken('t')->plainTextToken)
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment")
            ->assertStatus(403);

        $this->assertSame(0, Transaction::count());
    }
}
