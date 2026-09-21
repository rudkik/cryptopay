<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The per-service payment window (`settings.invoice_ttl`) and how it feeds `expires_at`. */
class InvoiceTtlSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();
        Carbon::setTestNow('2026-09-21 12:00:00');
    }

    public function test_an_invoice_without_expires_in_uses_the_service_payment_window(): void
    {
        [, $key] = $this->makeMerchant(['settings' => ['invoice_ttl' => 900]]);

        $id = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('2026-09-21 12:15:00', Invoice::findOrFail($id)->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_the_default_window_is_one_hour(): void
    {
        [, $key] = $this->makeMerchant();

        $id = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10'])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('2026-09-21 13:00:00', Invoice::findOrFail($id)->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_an_explicit_expires_in_still_wins_over_the_setting(): void
    {
        [, $key] = $this->makeMerchant(['settings' => ['invoice_ttl' => 900]]);

        $id = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'expires_in' => 120])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('2026-09-21 12:02:00', Invoice::findOrFail($id)->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_an_admin_sets_the_window_and_the_api_reports_it(): void
    {
        [$merchant] = $this->makeMerchant();
        $token = $this->adminToken(User::factory()->create(['role' => UserRole::Admin->value]));

        $this->withToken($token)
            ->putJson("/api/admin/merchants/{$merchant->id}", ['settings' => ['invoice_ttl' => 1800]])
            ->assertOk()
            ->assertJsonPath('data.invoice_ttl', 1800)
            ->assertJsonPath('data.settings.invoice_ttl', 1800);

        $this->assertSame(1800, $merchant->fresh()->invoiceTtl());
    }

    public function test_the_window_is_bounded_to_one_minute_and_one_day(): void
    {
        [$merchant] = $this->makeMerchant();
        $token = $this->adminToken(User::factory()->create(['role' => UserRole::Admin->value]));

        foreach ([30, 90000, 'soon'] as $bad) {
            $response = $this->withToken($token)
                ->putJson("/api/admin/merchants/{$merchant->id}", ['settings' => ['invoice_ttl' => $bad]]);

            $this->assertInvalidField($response, 'settings.invoice_ttl');
        }
    }

    public function test_a_stored_value_outside_the_bounds_is_clamped_when_read(): void
    {
        $merchant = Merchant::factory()->create(['settings' => ['invoice_ttl' => 999999]]);

        $this->assertSame(Merchant::MAX_INVOICE_TTL, $merchant->invoiceTtl());
    }
}
