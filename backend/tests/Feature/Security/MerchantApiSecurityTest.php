<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Token;
use App\Rules\BoundedMetadata;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MerchantApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();

        // Token sale is an optional module and ships off (config/features.php);
        // these cases exercise it, so they turn it on explicitly.
        config()->set('features.token_sale', true);

        [$this->merchant, $this->key] = $this->makeMerchant();
    }

    private function createInvoice(array $payload): TestResponse
    {
        return $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', array_merge([
                'amount' => '100', 'currency' => 'USDT', 'network' => 'tron',
            ], $payload));
    }

    // ------------------------------------------------------------------- urls

    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(document.cookie)'],
            'javascript with a comment bypass' => ['javascript://example.com/%0aalert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'file' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('dangerousUrls')]
    public function test_only_http_urls_are_accepted_for_the_redirect_targets(string $url): void
    {
        $this->assertInvalidField($this->createInvoice(['success_url' => $url]), 'success_url');
        $this->assertInvalidField($this->createInvoice(['cancel_url' => $url]), 'cancel_url');
    }

    public function test_http_and_https_redirect_targets_are_accepted(): void
    {
        $this->createInvoice([
            'success_url' => 'https://shop.example/thanks',
            'cancel_url' => 'http://shop.example/cancelled',
        ])->assertCreated();
    }

    // --------------------------------------------------------------- metadata

    public function test_metadata_is_bounded_in_size(): void
    {
        $this->assertInvalidField(
            $this->createInvoice(['metadata' => ['blob' => str_repeat('a', BoundedMetadata::MAX_BYTES)]]),
            'metadata',
        );
    }

    public function test_metadata_is_bounded_in_depth(): void
    {
        $deep = 'leaf';

        for ($i = 0; $i < BoundedMetadata::MAX_DEPTH + 2; $i++) {
            $deep = ['nested' => $deep];
        }

        $this->assertInvalidField($this->createInvoice(['metadata' => $deep]), 'metadata');
    }

    public function test_metadata_must_be_an_object(): void
    {
        $this->assertInvalidField($this->createInvoice(['metadata' => ['a', 'b', 'c']]), 'metadata');
    }

    public function test_reasonable_metadata_is_accepted(): void
    {
        $this->createInvoice(['metadata' => ['order_id' => 42, 'tags' => ['vip', 'eu']]])
            ->assertCreated()
            ->assertJsonPath('data.metadata.order_id', 42);
    }

    // ---------------------------------------------------------------- amounts

    /**
     * @return array<string, array{0: string}>
     */
    public static function badAmounts(): array
    {
        return [
            'zero' => ['0'],
            'zero with decimals' => ['0.000'],
            'below the smallest settleable unit' => ['0.0000001'],
            'past the ceiling' => ['1000000001'],
            'absurd' => ['999999999999999999'],
        ];
    }

    #[DataProvider('badAmounts')]
    public function test_out_of_range_amounts_are_rejected(string $amount): void
    {
        $this->assertInvalidField($this->createInvoice(['amount' => $amount]), 'amount');

        $this->assertSame(0, Invoice::count());
    }

    public function test_amounts_at_the_edges_are_accepted(): void
    {
        $this->createInvoice(['amount' => '0.000001'])->assertCreated();
        $this->createInvoice(['amount' => '1000000000'])->assertCreated();
    }

    // ------------------------------------------------------------------ scope

    public function test_every_read_is_scoped_to_the_authenticated_merchant(): void
    {
        [$other, $otherKey] = $this->makeMerchant();

        $otherInvoiceId = $this->withHeaders($this->keyHeaders($otherKey))
            ->postJson('/api/v1/invoices', ['amount' => '5', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()->json('data.id');

        $otherToken = Token::factory()->create(['merchant_id' => $other->id, 'is_active' => true]);

        $mine = $this->keyHeaders($this->key);

        $this->withHeaders($mine)->getJson("/api/v1/invoices/{$otherInvoiceId}")->assertStatus(404);
        $this->withHeaders($mine)->getJson("/api/v1/tokens/{$otherToken->id}")->assertStatus(404);
        $this->withHeaders($mine)->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($mine)->getJson('/api/v1/tokens')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_idempotency_key_is_scoped_to_the_merchant_that_issued_it(): void
    {
        [, $otherKey] = $this->makeMerchant();

        $mine = $this->withHeaders($this->keyHeaders($this->key, ['Idempotency-Key' => 'shared-key']))
            ->postJson('/api/v1/invoices', ['amount' => '100', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()->json('data.id');

        $theirs = $this->withHeaders($this->keyHeaders($otherKey, ['Idempotency-Key' => 'shared-key']))
            ->postJson('/api/v1/invoices', ['amount' => '7', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()->json('data.id');

        $this->assertNotSame($mine, $theirs, "merchant B replayed merchant A's response");
        $this->assertSame($this->merchant->id, Invoice::findOrFail($mine)->merchant_id);
    }

    public function test_an_idempotency_key_is_scoped_per_endpoint(): void
    {
        $headers = $this->keyHeaders($this->key, ['Idempotency-Key' => 'same-key']);

        $token = Token::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_active' => true,
            'price_usd' => '0.25',
            'min_purchase' => '1',
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/v1/invoices', ['amount' => '100', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated();

        // Same key, different endpoint: must not replay the invoice response.
        $this->withHeaders($headers)
            ->postJson('/api/v1/token-purchases', [
                'token_id' => $token->id, 'token_amount' => '10',
                'currency' => 'USDT', 'network' => 'tron', 'customer_id' => 'cust-1',
            ])
            ->assertCreated()
            ->assertJsonStructure(['purchase', 'invoice']);
    }

    // ------------------------------------------------------------------- keys

    public function test_a_revoked_key_stops_working(): void
    {
        $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/me')->assertOk();

        app(ApiKeyService::class)->revoke($this->merchant->apiKeys()->firstOrFail());

        $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_disabled_merchant_is_blocked(): void
    {
        $this->merchant->forceFill(['is_active' => false])->save();

        $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_the_merchant_api_never_returns_the_webhook_secret(): void
    {
        $this->merchant->forceFill(['webhook_url' => 'https://merchant.test/hooks'])->save();

        $body = $this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/me')->assertOk()->json();

        $encoded = json_encode($body);

        $this->assertStringNotContainsString('webhook_secret', $encoded);
        $this->assertStringNotContainsString($this->merchant->webhook_secret, $encoded);
    }

    public function test_pagination_is_bounded(): void
    {
        $this->assertInvalidField(
            $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/invoices?per_page=100000'),
            'per_page',
        );
    }

    public function test_the_rate_limit_is_keyed_per_api_key_not_per_ip(): void
    {
        [, $otherKey] = $this->makeMerchant();

        for ($i = 0; $i < 120; $i++) {
            $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/networks')->assertOk();
        }

        $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/networks')
            ->assertStatus(429);

        // Same IP, different credential: unaffected.
        $this->withHeaders($this->keyHeaders($otherKey))->getJson('/api/v1/networks')->assertOk();
    }
}
