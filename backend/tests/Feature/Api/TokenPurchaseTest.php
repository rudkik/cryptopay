<?php

namespace Tests\Feature\Api;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Token;
use App\Models\TokenHolding;
use App\Models\TokenPurchase;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TokenPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $key;

    private Token $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();

        // Token sale is an optional module and ships off (config/features.php);
        // these cases exercise it, so they turn it on explicitly.
        config()->set('features.token_sale', true);

        [$this->merchant, $this->key] = $this->makeMerchant(['webhook_url' => 'https://merchant.test/hooks']);

        $this->token = Token::factory()->create([
            'merchant_id' => $this->merchant->id,
            'symbol' => 'DEMO',
            'name' => 'Demo Token',
            'price_usd' => '0.25',
            'decimals' => 18,
            'total_supply' => '1000000',
            'min_purchase' => '1',
            'max_purchase' => '100000',
        ]);
    }

    private function purchase(array $overrides = []): array
    {
        return $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/token-purchases', array_merge([
                'token_id' => $this->token->id,
                'token_amount' => '400',
                'currency' => 'USDT',
                'network' => 'tron',
                'customer_id' => 'cust-1',
                'customer_email' => 'buyer@example.com',
            ], $overrides))
            ->assertCreated()
            ->json();
    }

    public function test_a_purchase_creates_an_invoice_priced_from_the_token(): void
    {
        $body = $this->purchase();

        // 400 tokens at 0.25 USD = 100 USDT.
        $this->assertSame('400.000000000000000000', $body['purchase']['token_amount']);
        $this->assertSame('0.25', $body['purchase']['price_usd']);
        $this->assertSame('100.000000', $body['purchase']['pay_amount']);
        $this->assertSame('pending', $body['purchase']['status']);
        $this->assertSame('token_purchase', $body['invoice']['type']);
        $this->assertSame('100.000000', $body['invoice']['amount']);
        $this->assertSame($body['invoice']['id'], $body['purchase']['invoice_id']);
    }

    public function test_a_purchase_can_be_specified_by_pay_amount_instead(): void
    {
        $body = $this->purchase(['token_amount' => null, 'pay_amount' => '50']);

        $this->assertSame('200.000000000000000000', $body['purchase']['token_amount']);
        $this->assertSame('50.000000', $body['purchase']['pay_amount']);
    }

    public function test_paying_the_invoice_completes_the_purchase_and_credits_holdings(): void
    {
        $body = $this->purchase();
        $invoice = Invoice::with('depositAddress')->findOrFail($body['invoice']['id']);

        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', [
            'network' => 'tron',
            'tx_hash' => $this->txHash('token-purchase'),
            'log_index' => 0,
            'symbol' => 'USDT',
            'to_address' => $invoice->depositAddress->address,
            'amount' => '100',
            'amount_raw' => '100000000',
            'confirmations' => 19,
            'status' => 'confirmed',
        ])->assertOk();

        $this->assertSame('paid', $invoice->refresh()->status->value);

        $purchase = TokenPurchase::findOrFail($body['purchase']['id']);
        $this->assertSame('completed', $purchase->status->value);
        $this->assertNotNull($purchase->completed_at);

        $this->assertSame('400.000000000000000000', Money::format($this->token->refresh()->sold, 18));

        $holding = TokenHolding::where('token_id', $this->token->id)->where('customer_id', 'cust-1')->firstOrFail();
        $this->assertSame('400.000000000000000000', Money::format($holding->amount, 18));

        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'token_purchase.completed']);

        // The holdings endpoint reflects it.
        $this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/customers/cust-1/holdings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', '400.000000000000000000')
            ->assertJsonPath('data.0.token.symbol', 'DEMO');
    }

    public function test_a_second_confirmation_does_not_double_credit_the_holding(): void
    {
        $body = $this->purchase();
        $invoice = Invoice::with('depositAddress')->findOrFail($body['invoice']['id']);

        $payload = [
            'network' => 'tron',
            'tx_hash' => $this->txHash('token-purchase'),
            'log_index' => 0,
            'symbol' => 'USDT',
            'to_address' => $invoice->depositAddress->address,
            'amount' => '100',
            'amount_raw' => '100000000',
            'confirmations' => 19,
            'status' => 'confirmed',
        ];

        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', $payload)->assertOk();
        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', $payload)->assertOk();

        $holding = TokenHolding::where('token_id', $this->token->id)->firstOrFail();
        $this->assertSame('400.000000000000000000', Money::format($holding->amount, 18));
        $this->assertSame('400.000000000000000000', Money::format($this->token->refresh()->sold, 18));
        $this->assertSame(1, TokenHolding::count());
    }

    public function test_an_expired_purchase_invoice_expires_the_purchase(): void
    {
        $body = $this->purchase();

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('expired', TokenPurchase::findOrFail($body['purchase']['id'])->status->value);
        $this->assertSame('0.000000000000000000', Money::format($this->token->refresh()->sold, 18));

        Carbon::setTestNow();
    }

    public function test_a_purchase_below_the_minimum_is_rejected(): void
    {
        $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/token-purchases', [
                'token_id' => $this->token->id,
                'token_amount' => '0.5',
                'currency' => 'USDT',
                'network' => 'tron',
                'customer_id' => 'cust-1',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');
    }

    public function test_a_purchase_beyond_the_remaining_supply_is_rejected(): void
    {
        $this->token->forceFill(['sold' => '999000'])->save();

        $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/token-purchases', [
                'token_id' => $this->token->id,
                'token_amount' => '5000',
                'currency' => 'USDT',
                'network' => 'tron',
                'customer_id' => 'cust-1',
            ])
            ->assertStatus(409);
    }

    public function test_a_purchase_for_another_merchants_token_is_not_found(): void
    {
        [, $otherKey] = $this->makeMerchant();

        $this->withHeaders($this->keyHeaders($otherKey))
            ->postJson('/api/v1/token-purchases', [
                'token_id' => $this->token->id,
                'token_amount' => '10',
                'currency' => 'USDT',
                'network' => 'tron',
                'customer_id' => 'cust-1',
            ])
            ->assertStatus(404);
    }

    public function test_purchases_can_be_listed_by_customer(): void
    {
        $this->purchase();
        $this->purchase(['customer_id' => 'cust-2']);

        $this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/token-purchases?customer_id=cust-2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_id', 'cust-2');
    }
}
