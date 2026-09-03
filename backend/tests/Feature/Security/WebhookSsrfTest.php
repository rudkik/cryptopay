<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\SendWebhookJob;
use App\Models\Merchant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `webhook_url` is the only merchant-controlled value the backend itself
 * fetches, from a queue worker that sits on the compose network next to
 * postgres, redis and the watcher.
 */
class WebhookSsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();

        config()->set('services.webhooks.allow_private', false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/hook'],
            'loopback by name' => ['http://localhost:3000/hook'],
            'ipv6 loopback' => ['http://[::1]/hook'],
            'private class A' => ['http://10.1.2.3/hook'],
            'private class B' => ['http://172.16.9.9/hook'],
            'private class C' => ['http://192.168.0.5/hook'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'unspecified' => ['http://0.0.0.0/hook'],
            'unique local ipv6' => ['http://[fd00::1]/hook'],
            'compose service name' => ['http://watcher:3100/rescan'],
            'compose database' => ['http://postgres:5432/'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_the_guard_rejects_internal_targets(string $url): void
    {
        $this->assertNotNull(OutboundUrlGuard::reject($url), "{$url} was allowed");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedSchemes(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://example.com:70/_x'],
            'relative' => ['/hooks/incoming'],
        ];
    }

    #[DataProvider('blockedSchemes')]
    public function test_the_guard_rejects_non_http_schemes(string $url): void
    {
        $this->assertNotNull(OutboundUrlGuard::reject($url), "{$url} was allowed");
    }

    public function test_a_public_host_is_allowed(): void
    {
        $this->assertNull(OutboundUrlGuard::reject('https://merchant.test/hooks'));
        $this->assertNull(OutboundUrlGuard::reject('https://example.com/hooks'));
    }

    public function test_the_local_development_flag_reopens_private_targets(): void
    {
        $this->assertNotNull(OutboundUrlGuard::reject('http://host.docker.internal:3000/hook'));

        config()->set('services.webhooks.allow_private', true);

        $this->assertNull(OutboundUrlGuard::reject('http://host.docker.internal:3000/hook'));
        $this->assertNull(OutboundUrlGuard::reject('http://127.0.0.1:3000/hook'));

        // The scheme check is never relaxed.
        $this->assertNotNull(OutboundUrlGuard::reject('javascript:alert(1)'));
    }

    public function test_the_admin_api_refuses_an_internal_webhook_url(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $token = $admin->createToken('t')->plainTextToken;

        $this->assertInvalidField(
            $this->withToken($token)->postJson('/api/admin/merchants', [
                'name' => 'SSRF Shop',
                'webhook_url' => 'http://169.254.169.254/latest/meta-data/',
            ]),
            'webhook_url',
        );

        $merchant = Merchant::factory()->create();

        $this->assertInvalidField(
            $this->withToken($token)->putJson("/api/admin/merchants/{$merchant->id}", [
                'webhook_url' => 'http://watcher:3100/rescan',
            ]),
            'webhook_url',
        );

        $this->assertNull($merchant->refresh()->webhook_url);
    }

    /**
     * DNS can be re-pointed after the URL was accepted, so the delivery job
     * re-checks rather than trusting the stored value.
     */
    public function test_a_stored_internal_url_is_blocked_at_delivery_time(): void
    {
        $merchant = Merchant::factory()->create();

        $delivery = new WebhookDelivery;
        $delivery->id = (string) Str::uuid();
        $delivery->forceFill([
            'merchant_id' => $merchant->id,
            'event' => 'invoice.paid',
            'url' => 'http://169.254.169.254/latest/meta-data/',
            'payload' => '{"id":"x"}',
            'attempts' => 0,
            'max_attempts' => WebhookService::MAX_ATTEMPTS,
            'status' => WebhookDeliveryStatus::Pending->value,
            'next_attempt_at' => now(),
        ])->save();

        $this->resetHttpFakes();
        Http::fake(['*' => Http::response(['ok' => true])]);

        (new SendWebhookJob($delivery->id))->handle();

        Http::assertNothingSent();

        $delivery->refresh();
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('Blocked', (string) $delivery->last_error);
    }

    public function test_a_public_url_is_still_delivered(): void
    {
        $merchant = Merchant::factory()->create(['webhook_url' => 'https://merchant.test/hooks']);

        $delivery = app(WebhookService::class)->create($merchant, 'invoice.paid', ['invoice' => ['id' => 'x']]);

        $this->assertNotNull($delivery);
        $this->assertSame(
            WebhookDeliveryStatus::Delivered,
            $delivery->refresh()->status,
        );
    }
}
