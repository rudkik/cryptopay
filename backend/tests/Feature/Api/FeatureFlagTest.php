<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use App\Models\Network;
use App\Models\Token;
use App\Models\User;
use Database\Seeders\DemoMerchantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Token sale is an optional module (config/features.php, TOKEN_SALE_ENABLED).
 *
 * With the flag off its routes must be indistinguishable from paths this
 * deployment never had — the SPEC §6 `not_found` envelope — while payments, the
 * rest of the merchant API and the admin dashboard carry on untouched. Clients
 * learn the flag's value from `GET /api/public/config` (before login) and from
 * the admin login / auth me payloads (after).
 */
class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private const DISABLED_MESSAGE = 'Token sale module is disabled';

    private Merchant $merchant;

    private string $key;

    private string $admin;

    private Token $token;

    private string $purchaseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();

        [$this->merchant, $this->key] = $this->makeMerchant();
        $this->admin = $this->adminToken(User::factory()->create());

        $this->token = Token::factory()->create([
            'merchant_id' => $this->merchant->id,
            'symbol' => 'DEMO',
            'price_usd' => '0.25',
            'min_purchase' => '1',
        ]);

        // One real purchase to address by id, created through the API while the
        // module is on; the flag then goes back to the shipped default so every
        // case starts from "disabled".
        config()->set('features.token_sale', true);

        $this->purchaseId = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/token-purchases', [
                'token_id' => $this->token->id,
                'token_amount' => '400',
                'currency' => 'USDT',
                'network' => 'tron',
                'customer_id' => 'cust-1',
            ])
            ->assertCreated()
            ->json('purchase.id');

        config()->set('features.token_sale', false);

        // withHeaders() sticks for the rest of the test, so the fixture request
        // above would keep sending its API key on every later call — including
        // the ones that must arrive unauthenticated.
        $this->flushHeaders();
    }

    public function test_the_flag_is_off_by_default(): void
    {
        $this->assertFalse(config('features.token_sale'));
    }

    /**
     * Every route behind `feature:token_sale`, with the credential it needs and
     * the status it answers once the module is on.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function guardedRoutes(): array
    {
        return [
            'v1 list tokens' => ['GET', '/api/v1/tokens', 'merchant', 200],
            'v1 show token' => ['GET', '/api/v1/tokens/{token}', 'merchant', 200],
            'v1 list purchases' => ['GET', '/api/v1/token-purchases', 'merchant', 200],
            'v1 create purchase' => ['POST', '/api/v1/token-purchases', 'merchant', 201],
            'v1 show purchase' => ['GET', '/api/v1/token-purchases/{purchase}', 'merchant', 200],
            'v1 customer holdings' => ['GET', '/api/v1/customers/cust-1/holdings', 'merchant', 200],
            'admin list tokens' => ['GET', '/api/admin/tokens', 'admin', 200],
            'admin show token' => ['GET', '/api/admin/tokens/{token}', 'admin', 200],
            'admin token holdings' => ['GET', '/api/admin/tokens/{token}/holdings', 'admin', 200],
            'admin list purchases' => ['GET', '/api/admin/token-purchases', 'admin', 200],
            'admin show purchase' => ['GET', '/api/admin/token-purchases/{purchase}', 'admin', 200],
            'admin create token' => ['POST', '/api/admin/tokens', 'admin', 201],
            'admin update token' => ['PUT', '/api/admin/tokens/{token}', 'admin', 200],
            'admin retire token' => ['DELETE', '/api/admin/tokens/{token}', 'admin', 200],
        ];
    }

    #[DataProvider('guardedRoutes')]
    public function test_a_guarded_route_is_404_while_the_module_is_off(string $method, string $uri, string $actor): void
    {
        $this->request($method, $uri, $actor)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('error.message', self::DISABLED_MESSAGE);
    }

    #[DataProvider('guardedRoutes')]
    public function test_a_guarded_route_is_reachable_once_the_module_is_on(string $method, string $uri, string $actor, int $status): void
    {
        config()->set('features.token_sale', true);

        $response = $this->request($method, $uri, $actor);

        $response->assertStatus($status);
        $this->assertNotSame(
            self::DISABLED_MESSAGE,
            $response->json('error.message'),
            "[{$method} {$uri}] is still gated with the flag on.",
        );
    }

    /**
     * The gate must not answer before authentication does: a caller with no
     * credential still gets 401, so the flag's state is not something the whole
     * internet can probe route by route. Laravel re-sorts route middleware by
     * its priority list, so this is asserted rather than assumed (same trap as
     * Tests\Feature\Api\RateLimitTest).
     */
    public function test_authentication_still_runs_before_the_gate(): void
    {
        $this->getJson('/api/v1/tokens')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->getJson('/api/admin/tokens')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_the_disabled_envelope_is_exactly_the_spec_shape(): void
    {
        $this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/tokens')
            ->assertStatus(404)
            ->assertExactJson([
                'error' => [
                    'code' => 'not_found',
                    'message' => self::DISABLED_MESSAGE,
                    'details' => [],
                ],
            ]);
    }

    public function test_the_rest_of_the_merchant_api_is_unaffected(): void
    {
        $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', ['amount' => '100', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'payment')
            ->assertJsonPath('data.status', 'pending');

        $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/invoices')->assertOk();
        $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/networks')->assertOk();
        $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/balances')->assertOk();
        $this->withHeaders($this->keyHeaders($this->key))->getJson('/api/v1/me')->assertOk();
    }

    public function test_the_admin_dashboard_and_its_other_sections_are_unaffected(): void
    {
        $this->withToken($this->admin)->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['stats', 'chart', 'recent_invoices', 'networks']);

        $this->withToken($this->admin)->getJson('/api/admin/merchants')->assertOk();
        $this->withToken($this->admin)->getJson('/api/admin/invoices')->assertOk();
    }

    public function test_auth_me_and_login_carry_the_feature_map(): void
    {
        $this->withToken($this->admin)->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role', 'is_active'], 'features'])
            ->assertJsonPath('features.token_sale', false);

        User::factory()->create(['email' => 'flags@cryptopay.local', 'password' => 'secret-password-12']);

        $login = fn () => $this->postJson('/api/admin/auth/login', [
            'email' => 'flags@cryptopay.local',
            'password' => 'secret-password-12',
        ]);

        $login()->assertOk()
            ->assertJsonStructure(['token', 'user', 'features'])
            ->assertJsonPath('features.token_sale', false);

        config()->set('features.token_sale', true);

        $login()->assertOk()->assertJsonPath('features.token_sale', true);

        $this->forgetAuth()->withToken($this->admin)->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('features.token_sale', true);
    }

    public function test_the_public_config_is_readable_without_credentials(): void
    {
        $this->getJson('/api/public/config')
            ->assertOk()
            ->assertJsonPath('features.token_sale', false)
            // SPEC §2 order: ethereum, bsc, tron.
            ->assertExactJson([
                'features' => ['token_sale' => false],
                'networks' => ['ethereum', 'bsc', 'tron'],
            ]);

        config()->set('features.token_sale', true);

        $this->getJson('/api/public/config')->assertOk()->assertJsonPath('features.token_sale', true);
    }

    public function test_the_public_config_lists_only_enabled_networks(): void
    {
        Network::query()->where('code', 'bsc')->update(['is_enabled' => false]);

        $this->getJson('/api/public/config')
            ->assertOk()
            ->assertJsonPath('networks', ['ethereum', 'tron']);
    }

    /**
     * It sits inside the `public` prefix, so it inherits that group's per-IP
     * limiter (SPEC §6.3) instead of being an unthrottled hole.
     */
    public function test_the_public_config_shares_the_public_rate_limiter(): void
    {
        $route = collect($this->app['router']->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/public/config');

        $this->assertNotNull($route, 'GET /api/public/config is not registered.');
        $this->assertContains('throttle:public', $route->gatherMiddleware());
    }

    public function test_the_demo_seeder_skips_the_token_sale_rows_while_the_module_is_off(): void
    {
        $this->seed(DemoMerchantSeeder::class);

        $demo = Merchant::query()->where('name', 'Demo Shop')->firstOrFail();

        $this->assertSame(1, $demo->apiKeys()->count(), 'the demo merchant must still be seeded');
        $this->assertSame(0, Token::query()->where('merchant_id', $demo->id)->count());

        // Turning the module on later seeds it; the flag never deletes rows.
        config()->set('features.token_sale', true);
        $this->seed(DemoMerchantSeeder::class);

        $this->assertSame(1, Token::query()->where('merchant_id', $demo->id)->where('symbol', 'DEMO')->count());

        config()->set('features.token_sale', false);
        $this->seed(DemoMerchantSeeder::class);

        $this->assertSame(1, Token::query()->where('merchant_id', $demo->id)->where('symbol', 'DEMO')->count());
    }

    private function request(string $method, string $uri, string $actor): TestResponse
    {
        $uri = str_replace(
            ['{token}', '{purchase}'],
            [$this->token->id, $this->purchaseId],
            $uri,
        );

        $test = $actor === 'admin'
            ? $this->forgetAuth()->withToken($this->admin)
            : $this->withHeaders($this->keyHeaders($this->key));

        return match ($method) {
            'GET' => $test->getJson($uri),
            'POST' => $test->postJson($uri, $this->body($uri)),
            'PUT' => $test->putJson($uri, $this->body($uri)),
            'DELETE' => $test->deleteJson($uri),
        };
    }

    /** @return array<string, mixed> */
    private function body(string $uri): array
    {
        return match (true) {
            str_contains($uri, '/api/v1/token-purchases') => [
                'token_id' => $this->token->id,
                'token_amount' => '4',
                'currency' => 'USDT',
                'network' => 'tron',
                'customer_id' => 'cust-2',
            ],
            str_contains($uri, '/api/admin/tokens/') => ['name' => 'Renamed Token'],
            default => [
                'merchant_id' => $this->merchant->id,
                'symbol' => 'NEW',
                'name' => 'New Token',
                'price_usd' => '1.5',
            ],
        };
    }
}
