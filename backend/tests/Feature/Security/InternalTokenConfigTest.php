<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\AuthenticateInternal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `INTERNAL_API_TOKEN` is the only thing between the internet and the endpoint
 * that credits merchant balances. A deployment that never changed it from the
 * shipped placeholder should fail closed rather than accept a value anyone who
 * has read the repository already knows.
 */
class InternalTokenConfigTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableTokens(): array
    {
        return [
            'the .env.example placeholder' => ['change-me-internal-token-please'],
            'the SPEC placeholder' => ['change-me-internal-token'],
            'a changeme variant' => ['changeme'],
            'too short to matter' => ['abc123'],
        ];
    }

    #[DataProvider('unusableTokens')]
    public function test_a_placeholder_token_fails_closed_outside_local(string $token): void
    {
        config()->set('services.internal.token', $token);
        $this->app->detectEnvironment(fn () => 'production');

        $this->withHeaders(['X-Internal-Token' => $token, 'Accept' => 'application/json'])
            ->getJson('/api/internal/config')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'server_error');
    }

    public function test_an_empty_token_fails_closed_everywhere(): void
    {
        config()->set('services.internal.token', '');

        $this->getJson('/api/internal/config')->assertStatus(503);

        $this->withHeaders(['X-Internal-Token' => '', 'Accept' => 'application/json'])
            ->getJson('/api/internal/config')
            ->assertStatus(503);
    }

    public function test_a_placeholder_token_is_tolerated_in_local_development(): void
    {
        config()->set('services.internal.token', 'change-me-internal-token-please');
        $this->app->detectEnvironment(fn () => 'local');

        $this->withHeaders([
            'X-Internal-Token' => 'change-me-internal-token-please',
            'Accept' => 'application/json',
        ])->getJson('/api/internal/config')->assertOk();
    }

    public function test_a_real_token_is_accepted_and_a_wrong_one_is_not(): void
    {
        $token = str_repeat('a1b2c3d4', 8);

        config()->set('services.internal.token', $token);
        $this->app->detectEnvironment(fn () => 'production');

        $this->withHeaders(['X-Internal-Token' => $token, 'Accept' => 'application/json'])
            ->getJson('/api/internal/config')->assertOk();

        $this->withHeaders(['X-Internal-Token' => $token.'x', 'Accept' => 'application/json'])
            ->getJson('/api/internal/config')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_the_internal_routes_reject_an_absent_token(): void
    {
        foreach ([
            ['get', '/api/internal/config'],
            ['get', '/api/internal/watch-addresses?network=tron'],
            ['post', '/api/internal/transactions'],
            ['post', '/api/internal/transactions/batch'],
            ['post', '/api/internal/heartbeat'],
        ] as [$method, $uri]) {
            $this->{$method.'Json'}($uri)->assertStatus(401);
        }
    }

    public function test_the_misconfiguration_check_itself(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame('empty', AuthenticateInternal::misconfiguration(''));
        $this->assertSame('placeholder value', AuthenticateInternal::misconfiguration('change-me-x'));
        $this->assertNull(AuthenticateInternal::misconfiguration(str_repeat('z', 32)));
    }
}
