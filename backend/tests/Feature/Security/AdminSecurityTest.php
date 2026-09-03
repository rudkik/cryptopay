<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value, 'is_active' => true]);
    }

    // ---------------------------------------------------------------- tokens

    public function test_sanctum_tokens_expire(): void
    {
        $this->assertNotNull(
            config('sanctum.expiration'),
            'an admin token that never expires is a permanent credential in localStorage',
        );
        $this->assertLessThanOrEqual(720, (int) config('sanctum.expiration'));
    }

    public function test_logout_revokes_the_token_it_was_called_with(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/auth/logout')->assertOk();

        $this->assertSame(0, $admin->tokens()->count());

        $this->forgetAuth()
            ->withToken($token)->getJson('/api/admin/auth/me')
            ->assertStatus(401);
    }

    public function test_a_deactivated_user_loses_read_access_immediately(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/merchants')->assertOk();

        $admin->forceFill(['is_active' => false])->save();

        // Read routes are only guarded by `active`; without it an existing token
        // keeps working until it expires.
        $this->forgetAuth()->withToken($token)->getJson('/api/admin/merchants')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_a_viewer_cannot_reach_mutating_routes(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

        $this->withToken($viewer->createToken('t')->plainTextToken)
            ->postJson('/api/admin/merchants', ['name' => 'Nope'])
            ->assertStatus(403);
    }

    // ------------------------------------------------------------ login rules

    public function test_login_is_throttled_per_email_and_ip(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/auth/login', [
                'email' => $admin->email, 'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email, 'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_login_does_not_distinguish_a_missing_user_from_a_wrong_password(): void
    {
        $admin = $this->admin();

        $unknown = $this->postJson('/api/admin/auth/login', [
            'email' => 'nobody@cryptopay.local', 'password' => 'whatever-it-is',
        ])->assertStatus(422);

        $wrong = $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email, 'password' => 'whatever-it-is',
        ])->assertStatus(422);

        $this->assertSame($unknown->json('error'), $wrong->json('error'));
    }

    // ------------------------------------------------------------ user safety

    public function test_a_short_password_is_rejected(): void
    {
        $this->assertInvalidField(
            $this->withToken($this->admin()->createToken('t')->plainTextToken)
                ->postJson('/api/admin/users', [
                    'name' => 'Short', 'email' => 'short@cryptopay.local',
                    'password' => 'password123', 'role' => 'viewer',
                ]),
            'password',
        );
    }

    public function test_the_last_active_admin_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('t')->plainTextToken;
        $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

        $this->withToken($token)->putJson("/api/admin/users/{$admin->id}", ['role' => 'viewer'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');

        $this->withToken($token)->putJson("/api/admin/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(409);

        // Someone else deleting the last admin is the same hole from the other side.
        $other = User::factory()->create(['role' => UserRole::Admin->value]);
        $otherToken = $other->createToken('t')->plainTextToken;

        $this->forgetAuth()->withToken($otherToken)
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertOk();

        $this->forgetAuth()->withToken($otherToken)
            ->putJson("/api/admin/users/{$other->id}", ['role' => 'viewer'])
            ->assertStatus(409);

        $this->assertSame(UserRole::Admin, $other->refresh()->role);
        $this->assertTrue($viewer->exists);
    }

    public function test_an_admin_can_be_demoted_while_another_admin_remains(): void
    {
        $admin = $this->admin();
        $second = $this->admin();

        $this->forgetAuth()->withToken($admin->createToken('t')->plainTextToken)
            ->putJson("/api/admin/users/{$second->id}", ['role' => 'viewer'])
            ->assertOk();

        $this->assertSame(UserRole::Viewer, $second->refresh()->role);
    }

    public function test_the_users_resource_never_exposes_the_password_hash(): void
    {
        $admin = $this->admin();

        $body = $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json();

        $encoded = json_encode($body);

        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('remember_token', $encoded);
        $this->assertStringNotContainsString(substr($admin->password, 0, 20), $encoded);
    }

    // ------------------------------------------------------------- audit logs

    public function test_sensitive_admin_actions_are_written_to_the_audit_trail(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('t')->plainTextToken;

        $merchantId = $this->withToken($token)->postJson('/api/admin/merchants', [
            'name' => 'Audited Shop', 'email' => 'audited@cryptopay.local',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->putJson("/api/admin/merchants/{$merchantId}", [
            'name' => 'Audited Shop Renamed',
        ])->assertOk();

        $keyId = $this->withToken($token)
            ->postJson("/api/admin/merchants/{$merchantId}/api-keys", ['name' => 'CI key'])
            ->assertCreated()->json('api_key.id');

        $this->withToken($token)
            ->deleteJson("/api/admin/merchants/{$merchantId}/api-keys/{$keyId}")
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/admin/merchants/{$merchantId}/webhook-secret/rotate")
            ->assertOk();

        $this->withToken($token)->putJson('/api/admin/networks/tron', ['confirmations_required' => 21])->assertOk();
        $this->withToken($token)->putJson('/api/admin/networks/tron/tokens/USDT', ['decimals' => 6])->assertOk();

        $userId = $this->withToken($token)->postJson('/api/admin/users', [
            'name' => 'Audited', 'email' => 'audited-user@cryptopay.local',
            'password' => self::STRONG_PASSWORD, 'role' => 'viewer',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->putJson("/api/admin/users/{$userId}", ['name' => 'Renamed'])->assertOk();
        $this->withToken($token)->deleteJson("/api/admin/users/{$userId}")->assertOk();

        foreach ([
            'merchant.created', 'merchant.updated', 'merchant.webhook_secret_rotated',
            'api_key.created', 'api_key.revoked',
            'network.updated', 'token_contract.updated',
            'user.created', 'user.updated', 'user.deleted',
        ] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'user_id' => $admin->id]);
        }
    }

    public function test_invoice_cancellation_and_simulation_are_audited(): void
    {
        [, $key] = $this->makeMerchant();

        $invoiceId = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()->json('data.id');

        $token = $this->admin()->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/invoices/{$invoiceId}/simulate-payment", [
            'confirmed' => false,
        ])->assertOk();

        $simulated = AuditLog::where('action', 'invoice.payment_simulated')->firstOrFail();
        $this->assertSame($invoiceId, $simulated->subject_id);
        $this->assertSame('USDT', $simulated->changes['currency']);

        $second = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/admin/invoices/{$second}/cancel")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.cancelled', 'subject_id' => $second]);
    }

    public function test_the_audit_trail_never_stores_a_secret(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('t')->plainTextToken;

        $userId = $this->withToken($token)->postJson('/api/admin/users', [
            'name' => 'Secretive', 'email' => 'secretive@cryptopay.local',
            'password' => self::STRONG_PASSWORD, 'role' => 'viewer',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->putJson("/api/admin/users/{$userId}", [
            'password' => 'another-long-password',
        ])->assertOk();

        $merchant = Merchant::factory()->create();
        $this->withToken($token)
            ->postJson("/api/admin/merchants/{$merchant->id}/webhook-secret/rotate")
            ->assertOk();

        $trail = json_encode(AuditLog::all()->toArray());

        $this->assertStringNotContainsString(self::STRONG_PASSWORD, $trail);
        $this->assertStringNotContainsString('another-long-password', $trail);
        $this->assertStringNotContainsString($merchant->refresh()->webhook_secret, $trail);
        $this->assertStringNotContainsString('$2y$', $trail, 'a password hash reached the audit trail');
    }

    public function test_a_created_api_key_is_never_stored_in_plaintext(): void
    {
        $merchant = Merchant::factory()->create();
        $admin = $this->admin();

        $plaintext = $this->withToken($admin->createToken('t')->plainTextToken)
            ->postJson("/api/admin/merchants/{$merchant->id}/api-keys", ['name' => 'k'])
            ->assertCreated()->json('key');

        $this->assertNotNull($plaintext);
        $this->assertSame(hash('sha256', $plaintext), ApiKey::firstOrFail()->key_hash);
        $this->assertStringNotContainsString($plaintext, json_encode(AuditLog::all()->toArray()));
    }

    // ------------------------------------------------------------- responses

    public function test_api_responses_carry_the_baseline_security_headers(): void
    {
        $response = $this->withToken($this->admin()->createToken('t')->plainTextToken)
            ->getJson('/api/admin/merchants')
            ->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_webhook_secret_is_never_returned_by_a_read_endpoint(): void
    {
        $merchant = Merchant::factory()->create(['webhook_url' => 'https://merchant.test/hooks']);
        $token = $this->admin()->createToken('t')->plainTextToken;

        $detail = $this->withToken($token)->getJson("/api/admin/merchants/{$merchant->id}")->assertOk();
        $list = $this->withToken($token)->getJson('/api/admin/merchants')->assertOk();

        foreach ([$detail, $list] as $response) {
            $this->assertStringNotContainsString($merchant->webhook_secret, json_encode($response->json()));
            $this->assertStringNotContainsString('webhook_secret', json_encode($response->json()));
        }

        // ... except once, on rotate.
        $rotated = $this->withToken($token)
            ->postJson("/api/admin/merchants/{$merchant->id}/webhook-secret/rotate")
            ->assertOk();

        $this->assertSame($merchant->refresh()->webhook_secret, $rotated->json('webhook_secret'));
        $this->assertArrayNotHasKey('webhook_secret', (array) $rotated->json('merchant'));
    }

    public function test_the_public_invoice_view_hides_merchant_and_customer_data(): void
    {
        [, $key] = $this->makeMerchant();

        $created = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', [
                'amount' => '10', 'currency' => 'USDT', 'network' => 'tron',
                'customer_email' => 'buyer@example.com',
                'customer_id' => 'cust-secret-1',
                'metadata' => ['internal_note' => 'do not leak'],
            ])->assertCreated()->json('data');

        $public = $this->getJson('/api/public/invoices/'.$created['id'])->assertOk();
        $encoded = json_encode($public->json());

        foreach (['buyer@example.com', 'cust-secret-1', 'do not leak', 'metadata'] as $needle) {
            $this->assertStringNotContainsString($needle, $encoded);
        }
    }

    public function test_a_malformed_invoice_id_is_a_clean_404(): void
    {
        $this->getJson('/api/public/invoices/not-a-uuid')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        Invoice::query()->count(); // no query blew up on a bad uuid literal
    }
}
