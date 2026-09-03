<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\User;
use App\Services\ApiKeyService;
use Database\Seeders\DemoMerchantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();
    }

    public function test_an_admin_logs_in_and_receives_a_sanctum_token(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@cryptopay.local',
            'password' => 'secret-password',
            'role' => UserRole::Admin->value,
        ]);

        $response = $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@cryptopay.local',
            'password' => 'secret-password',
        ])->assertOk();

        $token = $response->json('token');

        $this->assertNotEmpty($token);
        $response->assertJsonPath('user.email', 'admin@cryptopay.local')
            ->assertJsonPath('user.role', 'admin');

        $this->withToken($token)->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->withToken($token)->postJson('/api/admin/auth/logout')->assertOk();

        $this->assertSame(0, PersonalAccessToken::count());

        // Feature tests reuse one application instance across requests, so the
        // guard still holds the user it resolved a moment ago. A real request
        // gets a fresh container; forget the guard to model that.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_bad_credentials_are_rejected_with_the_error_envelope(): void
    {
        User::factory()->create(['email' => 'admin@cryptopay.local', 'password' => 'secret-password']);

        $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@cryptopay.local', 'password' => 'wrong',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_a_disabled_account_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'off@cryptopay.local', 'password' => 'secret-password', 'is_active' => false,
        ]);

        $this->postJson('/api/admin/auth/login', [
            'email' => 'off@cryptopay.local', 'password' => 'secret-password',
        ])->assertStatus(422);
    }

    public function test_admin_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_viewer_can_read_but_not_write(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);
        $token = $viewer->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/merchants')->assertOk();

        $this->withToken($token)->postJson('/api/admin/merchants', ['name' => 'Nope'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_the_dashboard_returns_the_spec_shape(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'stats' => [
                    'invoices_total', 'invoices_paid', 'volume_24h' => ['USDT', 'USDC'],
                    'volume_total', 'merchants_active', 'pending_webhooks',
                ],
                'chart', 'recent_invoices', 'networks',
            ])
            ->assertJsonCount(30, 'chart')
            ->assertJsonCount(3, 'networks');
    }

    public function test_an_admin_creates_a_merchant_and_issues_an_api_key(): void
    {
        $token = $this->adminToken();

        $merchantId = $this->withToken($token)
            ->postJson('/api/admin/merchants', ['name' => 'Acme', 'email' => 'acme@example.com'])
            ->assertCreated()
            ->json('data.id');

        $response = $this->withToken($token)
            ->postJson("/api/admin/merchants/{$merchantId}/api-keys", ['name' => 'Production'])
            ->assertCreated();

        $plaintext = $response->json('key');

        $this->assertMatchesRegularExpression('/^cp_live_[0-9a-f]{40}$/', $plaintext);
        $this->assertSame(substr($plaintext, 0, 12), $response->json('api_key.key_prefix'));

        // The plaintext is never stored; only its sha256 hash.
        $this->assertDatabaseHas('api_keys', ['key_hash' => hash('sha256', $plaintext)]);
        $this->assertDatabaseMissing('api_keys', ['key_hash' => $plaintext]);

        // And it authenticates against the merchant API immediately.
        $this->withHeaders($this->keyHeaders($plaintext))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme');

        // Revoking it locks the key out.
        $this->withToken($token)
            ->deleteJson("/api/admin/merchants/{$merchantId}/api-keys/".$response->json('api_key.id'))
            ->assertOk();

        $this->withHeaders($this->keyHeaders($plaintext))->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_the_webhook_secret_can_be_rotated(): void
    {
        $merchant = Merchant::factory()->create();
        $before = $merchant->webhook_secret;

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/admin/merchants/{$merchant->id}/webhook-secret/rotate")
            ->assertOk();

        $this->assertNotSame($before, $response->json('webhook_secret'));
        $this->assertSame($response->json('webhook_secret'), $merchant->refresh()->webhook_secret);
        // The secret is never exposed through the merchant resource.
        $this->assertArrayNotHasKey('webhook_secret', $response->json('merchant'));
    }

    public function test_networks_and_token_contracts_are_editable(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->putJson('/api/admin/networks/tron', ['confirmations_required' => 25, 'is_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.confirmations_required', 25)
            ->assertJsonPath('data.is_enabled', false);

        $this->withToken($token)
            ->putJson('/api/admin/networks/tron/tokens/USDT', ['decimals' => 8, 'is_enabled' => false])
            ->assertOk();

        $this->assertDatabaseHas('token_contracts', [
            'network_code' => 'tron', 'symbol' => 'USDT', 'decimals' => 8, 'is_enabled' => false,
        ]);
    }

    public function test_a_disabled_network_cannot_be_invoiced(): void
    {
        $this->withToken($this->adminToken())
            ->putJson('/api/admin/networks/tron', ['is_enabled' => false])->assertOk();

        [, $key] = $this->makeMerchant();

        $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');
    }

    public function test_the_watcher_health_proxy_degrades_gracefully(): void
    {
        $this->resetHttpFakes();
        Http::fake(['*/health' => Http::response(['ok' => true, 'networks' => []])]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/watcher/health')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_admins_manage_users_and_cannot_delete_themselves(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $token = $admin->createToken('test')->plainTextToken;

        $created = $this->withToken($token)->postJson('/api/admin/users', [
            'name' => 'Viewer', 'email' => 'viewer@cryptopay.local',
            'password' => 'correct-horse-battery', 'role' => 'viewer',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');

        $this->withToken($token)->deleteJson("/api/admin/users/{$created}")->assertOk();
    }

    public function test_admin_invoice_search_matches_id_external_id_and_address(): void
    {
        [, $key] = $this->makeMerchant();

        $created = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', [
                'amount' => '10', 'currency' => 'USDT', 'network' => 'tron', 'external_id' => 'order-9',
            ])->assertCreated()->json('data');

        $token = $this->adminToken();

        // Searching by a fragment of the uuid requires an explicit text cast on
        // Postgres; this asserts the cast is in place.
        $this->withToken($token)
            ->getJson('/api/admin/invoices?q='.substr($created['id'], 0, 8))
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->getJson('/api/admin/invoices?q=order-9')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->getJson('/api/admin/invoices?q='.$created['address'])
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->getJson('/api/admin/invoices?q=nothing-matches-this')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_malformed_uuid_in_a_route_is_a_clean_404(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/admin/invoices/not-a-uuid')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        [, $key] = $this->makeMerchant();

        $this->withHeaders($this->keyHeaders($key))
            ->getJson('/api/v1/invoices/not-a-uuid')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_an_unknown_route_returns_the_not_found_envelope(): void
    {
        $this->getJson('/api/v1/nope')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_the_admin_invoice_view_includes_transactions_and_webhooks(): void
    {
        [, $key] = $this->makeMerchant(['webhook_url' => 'https://merchant.test/hooks']);

        $id = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->json('data.id');

        $this->withHeaders($this->keyHeaders($key))->postJson("/api/v1/invoices/{$id}/cancel")->assertOk();

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/invoices/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonCount(1, 'data.webhooks')
            ->assertJsonPath('data.webhooks.0.event', 'invoice.cancelled');

        $this->assertSame(1, Invoice::count());
    }

    public function test_the_cli_can_issue_an_api_key(): void
    {
        $merchant = Merchant::factory()->create(['name' => 'CLI Shop']);

        $this->artisan('cryptopay:create-api-key', ['merchant' => 'CLI Shop'])
            ->assertSuccessful();

        $this->assertSame(1, $merchant->apiKeys()->count());
        $this->assertSame('cp_live_', substr($merchant->apiKeys()->first()->key_prefix, 0, 8));
    }

    public function test_the_seeded_demo_merchant_key_is_deterministic_in_local(): void
    {
        $this->seed(DemoMerchantSeeder::class);

        $key = DemoMerchantSeeder::DEFAULT_KEY;
        $this->assertMatchesRegularExpression('/^cp_live_[0-9a-f]{40}$/', $key);

        $this->withHeaders($this->keyHeaders($key))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Demo Shop')
            ->assertJsonPath('data.webhook_url', null);

        $this->withHeaders($this->keyHeaders($key))->getJson('/api/v1/tokens')
            ->assertOk()
            ->assertJsonPath('data.0.symbol', 'DEMO')
            ->assertJsonPath('data.0.price_usd', '0.25');

        // Re-seeding must not create a duplicate key or merchant.
        $this->seed(DemoMerchantSeeder::class);
        $this->assertSame(1, Merchant::where('name', 'Demo Shop')->count());
        $this->assertSame(1, app(ApiKeyService::class)->resolve($key)->merchant->apiKeys()->count());
    }
}
