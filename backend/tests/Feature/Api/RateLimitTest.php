<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();
    }

    /**
     * Laravel re-sorts route middleware by its priority list, so the order
     * written in routes/api.php is not the order that runs. If throttling ever
     * moves ahead of authentication again, the per-key limit silently becomes a
     * per-IP one.
     */
    public function test_api_key_authentication_runs_before_throttling(): void
    {
        $router = $this->app['router'];

        $route = collect($router->getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/invoices' && in_array('POST', $r->methods(), true)
        );

        $middleware = array_map(
            fn ($m) => is_string($m) ? explode(':', $m)[0] : $m::class,
            $router->gatherRouteMiddleware($route),
        );

        $authIndex = array_search(AuthenticateApiKey::class, $middleware, true);
        $throttleIndex = array_search(ThrottleRequests::class, $middleware, true);

        $this->assertIsInt($authIndex, 'AuthenticateApiKey is not in the stack.');
        $this->assertIsInt($throttleIndex, 'ThrottleRequests is not in the stack.');
        $this->assertLessThan($throttleIndex, $authIndex);
    }

    public function test_the_limit_is_120_per_minute_and_scoped_to_one_key(): void
    {
        [, $keyA] = $this->makeMerchant();
        [, $keyB] = $this->makeMerchant();

        for ($i = 0; $i < 120; $i++) {
            $this->withHeaders($this->keyHeaders($keyA))->getJson('/api/v1/networks')->assertOk();
        }

        $this->withHeaders($this->keyHeaders($keyA))
            ->getJson('/api/v1/networks')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');

        // A different key has its own bucket.
        $this->withHeaders($this->keyHeaders($keyB))->getJson('/api/v1/networks')->assertOk();
    }
}
