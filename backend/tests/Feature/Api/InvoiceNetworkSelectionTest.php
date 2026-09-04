<?php

namespace Tests\Feature\Api;

use App\Models\DepositAddress;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Network;
use App\Models\Token;
use App\Models\TokenContract;
use App\Models\TokenHolding;
use App\Models\TokenPurchase;
use App\Models\Wallet;
use App\Support\NetworkRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SPEC §6.1 / §6.3 — an invoice may be created without a currency and network,
 * and the payer picks them on the hosted checkout. That selection is what
 * allocates the deposit address, so it has to happen at most once per invoice.
 */
class InvoiceNetworkSelectionTest extends TestCase
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

    /** An invoice created with nothing but an amount. */
    private function unselectedInvoice(array $overrides = []): Invoice
    {
        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', array_merge(['amount' => '100'], $overrides))
            ->assertCreated()
            ->json('data.id');

        return Invoice::query()->findOrFail($id);
    }

    public function test_an_invoice_can_be_created_without_a_currency_and_network(): void
    {
        $response = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', ['amount' => '100', 'external_id' => 'order-x'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.selection_required', true)
            ->assertJsonPath('data.currency', null)
            ->assertJsonPath('data.network', null)
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.qr_payload', null)
            ->assertJsonPath('data.amount', '100.000000');

        $invoice = Invoice::query()->findOrFail($response->json('data.id'));

        $this->assertNull($invoice->deposit_address_id);
        $this->assertNull($invoice->currency);
        $this->assertNull($invoice->network_code);

        // Nothing was derived, so no HD index was burned on any network.
        $this->assertSame(0, DepositAddress::query()->count());
        $this->assertSame([0, 0, 0], Wallet::query()->orderBy('network_code')->pluck('next_index')->all());
    }

    public function test_the_public_checkout_advertises_the_available_options(): void
    {
        $invoice = $this->unselectedInvoice();

        $response = $this->getJson("/api/public/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.selection_required', true)
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.qr_payload', null)
            ->assertJsonPath('data.network_name', null)
            ->assertJsonPath('data.token_contract', null);

        $options = $response->json('data.options');

        // 3 enabled networks x 2 enabled contracts (SPEC §2), ordered by network.
        $this->assertCount(6, $options);
        $this->assertSame(
            ['ethereum', 'ethereum', 'bsc', 'bsc', 'tron', 'tron'],
            array_column($options, 'network'),
        );
        $this->assertSame(
            ['USDC', 'USDT', 'USDC', 'USDT', 'USDC', 'USDT'],
            array_column($options, 'currency'),
        );
        $this->assertSame(
            ['ERC-20', 'ERC-20', 'BEP-20', 'BEP-20', 'TRC-20', 'TRC-20'],
            array_column($options, 'standard'),
        );

        $this->assertSame([
            'network' => 'tron',
            'network_name' => 'Tron',
            'chain_id' => null,
            'currency' => 'USDT',
            'confirmations_required' => 19,
            'standard' => 'TRC-20',
        ], $options[5]);

        $this->assertSame(1, $options[0]['chain_id']);
    }

    public function test_disabled_networks_and_contracts_are_not_offered(): void
    {
        Network::query()->where('code', 'bsc')->update(['is_enabled' => false]);
        TokenContract::query()->where('network_code', 'ethereum')->where('symbol', 'USDC')->update(['is_enabled' => false]);
        NetworkRegistry::make()->flush();

        $invoice = $this->unselectedInvoice();

        $options = $this->getJson("/api/public/invoices/{$invoice->id}")->assertOk()->json('data.options');

        $this->assertSame(
            [['ethereum', 'USDT'], ['tron', 'USDC'], ['tron', 'USDT']],
            array_map(fn ($o) => [$o['network'], $o['currency']], $options),
        );
    }

    public function test_selecting_a_network_allocates_the_deposit_address(): void
    {
        $invoice = $this->unselectedInvoice();

        $response = $this->postJson("/api/public/invoices/{$invoice->id}/select", [
            'currency' => 'USDT',
            'network' => 'tron',
        ])
            ->assertOk()
            ->assertJsonPath('data.selection_required', false)
            ->assertJsonPath('data.options', [])
            ->assertJsonPath('data.currency', 'USDT')
            ->assertJsonPath('data.network', 'tron')
            ->assertJsonPath('data.network_name', 'Tron')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.confirmations_required', 19)
            ->assertJsonPath('data.token_contract.symbol', 'USDT');

        $address = $response->json('data.address');

        $this->assertNotNull($address);
        // Tron's qr_payload is the bare address (SPEC §6.1).
        $this->assertSame($address, $response->json('data.qr_payload'));

        $invoice->refresh();
        $this->assertSame('USDT', $invoice->currency);
        $this->assertSame('tron', $invoice->network_code);
        $this->assertNotNull($invoice->deposit_address_id);

        // The address is bound to this invoice forever, and the HD index moved
        // only on the network that was actually chosen.
        $depositAddress = DepositAddress::query()->findOrFail($invoice->deposit_address_id);
        $this->assertSame($invoice->id, $depositAddress->invoice_id);
        $this->assertSame($this->merchant->id, $depositAddress->merchant_id);
        $this->assertSame(1, Wallet::query()->where('network_code', 'tron')->value('next_index'));
        $this->assertSame(0, Wallet::query()->where('network_code', 'ethereum')->value('next_index'));
    }

    public function test_selecting_an_evm_network_builds_an_eip_681_qr_payload(): void
    {
        $invoice = $this->unselectedInvoice();

        $response = $this->postJson("/api/public/invoices/{$invoice->id}/select", [
            'currency' => 'USDC',
            'network' => 'ethereum',
        ])->assertOk();

        $this->assertStringStartsWith('ethereum:', $response->json('data.qr_payload'));
        $this->assertStringContainsString('@1/transfer?address='.$response->json('data.address'), $response->json('data.qr_payload'));
        $this->assertStringContainsString('uint256=100000000', $response->json('data.qr_payload'));
    }

    public function test_a_second_selection_is_rejected_and_allocates_nothing(): void
    {
        $invoice = $this->unselectedInvoice();

        $this->postJson("/api/public/invoices/{$invoice->id}/select", ['currency' => 'USDT', 'network' => 'tron'])
            ->assertOk();

        $this->postJson("/api/public/invoices/{$invoice->id}/select", ['currency' => 'USDC', 'network' => 'ethereum'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');

        $invoice->refresh();
        $this->assertSame('USDT', $invoice->currency);
        $this->assertSame('tron', $invoice->network_code);

        // Exactly one address per invoice, and the losing network's HD index
        // was never consumed.
        $this->assertSame(1, DepositAddress::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, DepositAddress::query()->count());
        $this->assertSame(0, Wallet::query()->where('network_code', 'ethereum')->value('next_index'));
    }

    public function test_an_invoice_created_with_a_network_cannot_be_reselected(): void
    {
        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()
            ->assertJsonPath('data.selection_required', false)
            ->json('data.id');

        $this->postJson("/api/public/invoices/{$id}/select", ['currency' => 'USDC', 'network' => 'bsc'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');
    }

    public function test_selecting_on_an_expired_invoice_is_rejected(): void
    {
        $invoice = $this->unselectedInvoice(['expires_in' => 60]);

        Carbon::setTestNow(now()->addMinutes(5));

        $this->getJson("/api/public/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.selection_required', false)
            ->assertJsonPath('data.options', []);

        $this->postJson("/api/public/invoices/{$invoice->id}/select", ['currency' => 'USDT', 'network' => 'tron'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');

        $this->assertSame(0, DepositAddress::query()->count());

        Carbon::setTestNow();
    }

    public function test_an_unselected_invoice_simply_expires(): void
    {
        $invoice = $this->unselectedInvoice(['expires_in' => 60]);

        Carbon::setTestNow(now()->addMinutes(5));

        $this->artisan('invoices:expire')->assertSuccessful();

        $invoice->refresh();
        $this->assertSame('expired', $invoice->status->value);
        $this->assertSame('0.000000000000000000', (string) $invoice->amount_received);

        Carbon::setTestNow();
    }

    public function test_a_cancelled_invoice_cannot_be_selected(): void
    {
        $invoice = $this->unselectedInvoice();

        $this->withHeaders($this->keyHeaders($this->key))
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel")
            ->assertOk();

        $this->postJson("/api/public/invoices/{$invoice->id}/select", ['currency' => 'USDT', 'network' => 'tron'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');
    }

    public function test_an_unknown_or_unavailable_pair_is_rejected(): void
    {
        $invoice = $this->unselectedInvoice();

        $this->assertInvalidField(
            $this->postJson("/api/public/invoices/{$invoice->id}/select", ['currency' => 'USDT', 'network' => 'solana']),
            'network',
        );

        $this->assertInvalidField(
            $this->postJson("/api/public/invoices/{$invoice->id}/select", ['currency' => 'BTC', 'network' => 'tron']),
            'currency',
        );

        $this->assertInvalidField(
            $this->postJson("/api/public/invoices/{$invoice->id}/select", ['network' => 'tron']),
            'currency',
        );

        // Spellable, but not on offer: USDC is disabled on Tron.
        TokenContract::query()->where('network_code', 'tron')->where('symbol', 'USDC')->update(['is_enabled' => false]);
        NetworkRegistry::make()->flush();

        $this->assertInvalidField(
            $this->postJson("/api/public/invoices/{$invoice->id}/select", ['currency' => 'USDC', 'network' => 'tron']),
            'network',
        );

        $this->assertSame(0, DepositAddress::query()->count());
    }

    public function test_creating_an_invoice_with_only_one_of_the_pair_is_rejected(): void
    {
        $this->assertInvalidField(
            $this->withHeaders($this->keyHeaders($this->key))
                ->postJson('/api/v1/invoices', ['amount' => '10', 'network' => 'tron']),
            'currency',
        );

        $this->assertInvalidField(
            $this->withHeaders($this->keyHeaders($this->key))
                ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT']),
            'network',
        );

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_selecting_on_an_unknown_invoice_is_a_404(): void
    {
        $this->postJson('/api/public/invoices/'.Str::uuid7()->toString().'/select', [
            'currency' => 'USDT',
            'network' => 'tron',
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_merchant_can_select_through_its_own_api(): void
    {
        $invoice = $this->unselectedInvoice();

        $this->withHeaders($this->keyHeaders($this->key))
            ->postJson("/api/v1/invoices/{$invoice->id}/select", ['currency' => 'USDC', 'network' => 'bsc'])
            ->assertOk()
            ->assertJsonPath('data.selection_required', false)
            ->assertJsonPath('data.currency', 'USDC')
            ->assertJsonPath('data.network', 'bsc')
            ->assertJsonPath('data.metadata', []);

        $this->assertNotNull($invoice->refresh()->deposit_address_id);
    }

    public function test_a_merchant_cannot_select_another_merchants_invoice(): void
    {
        $invoice = $this->unselectedInvoice();

        [, $otherKey] = $this->makeMerchant();

        $this->withHeaders($this->keyHeaders($otherKey))
            ->postJson("/api/v1/invoices/{$invoice->id}/select", ['currency' => 'USDT', 'network' => 'tron'])
            ->assertStatus(404);

        $this->assertNull($invoice->refresh()->deposit_address_id);
    }

    public function test_the_admin_views_tolerate_an_unselected_invoice(): void
    {
        $invoice = $this->unselectedInvoice();
        $token = $this->adminToken();

        $this->withToken($token)->getJson('/api/admin/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.selection_required', true)
            ->assertJsonPath('data.0.currency', null)
            ->assertJsonPath('data.0.network', null)
            ->assertJsonPath('data.0.address', null);

        $this->forgetAuth()->withToken($token)->getJson("/api/admin/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.selection_required', true)
            ->assertJsonPath('data.qr_payload', null)
            ->assertJsonPath('data.merchant.id', $this->merchant->id);

        // There is no address to pay to, so the simulator has nothing to do.
        $this->forgetAuth()->withToken($token)
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment", ['confirmed' => true])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');
    }

    public function test_a_token_purchase_can_defer_its_network_until_the_payer_chooses(): void
    {
        $token = Token::factory()->create([
            'merchant_id' => $this->merchant->id,
            'symbol' => 'DEMO',
            'price_usd' => '0.25',
            'decimals' => 18,
            'min_purchase' => '1',
        ]);

        $created = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/token-purchases', [
                'token_id' => $token->id,
                'token_amount' => '400',
                'customer_id' => 'cust-1',
            ])
            ->assertCreated()
            ->assertJsonPath('invoice.selection_required', true)
            ->assertJsonPath('invoice.currency', null)
            ->assertJsonPath('invoice.address', null)
            ->assertJsonPath('purchase.currency', null)
            ->assertJsonPath('purchase.status', 'pending')
            ->json();

        $invoiceId = $created['invoice']['id'];

        // Supplying only one half of the pair is rejected here too.
        $this->assertInvalidField(
            $this->withHeaders($this->keyHeaders($this->key))->postJson('/api/v1/token-purchases', [
                'token_id' => $token->id,
                'token_amount' => '400',
                'customer_id' => 'cust-1',
                'network' => 'tron',
            ]),
            'currency',
        );

        $this->postJson("/api/public/invoices/{$invoiceId}/select", ['currency' => 'USDT', 'network' => 'tron'])
            ->assertOk()
            ->assertJsonPath('data.amount', '100.000000')
            ->assertJsonPath('data.token_purchase.status', 'pending');

        // The purchase is quoted in the currency the payer picked.
        $purchase = TokenPurchase::query()->where('invoice_id', $invoiceId)->firstOrFail();
        $this->assertSame('USDT', $purchase->currency);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/invoices/{$invoiceId}/simulate-payment", ['confirmed' => true])
            ->assertOk();

        $this->assertSame('paid', Invoice::query()->findOrFail($invoiceId)->status->value);
        $this->assertSame('completed', $purchase->refresh()->status->value);
        $this->assertSame(
            '400.000000000000000000',
            (string) TokenHolding::query()->where('token_id', $token->id)->where('customer_id', 'cust-1')->value('amount'),
        );
    }
}
