<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/testing/boom', function () {
            throw new RuntimeException('SQLSTATE[08006] password=hunter2 host=postgres');
        });
    }

    /**
     * APP_DEBUG=true is what .env.example ships, so the environment has to be
     * part of the gate: exception messages routinely carry SQL, file paths and
     * connection strings.
     */
    public function test_production_never_returns_the_exception_message_even_with_debug_on(): void
    {
        config()->set('app.debug', true);
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->getJson('/api/testing/boom')->assertStatus(500);

        $encoded = json_encode($response->json());

        $this->assertSame('server_error', $response->json('error.code'));
        $this->assertSame('Server error.', $response->json('error.message'));
        $this->assertStringNotContainsString('hunter2', $encoded);
        $this->assertStringNotContainsString('RuntimeException', $encoded);
        $this->assertStringNotContainsString('/app/', $encoded);
        $this->assertSame([], (array) $response->json('error.details'));
    }

    public function test_debug_off_hides_the_message_in_any_environment(): void
    {
        config()->set('app.debug', false);

        $response = $this->getJson('/api/testing/boom')->assertStatus(500);

        $this->assertSame('Server error.', $response->json('error.message'));
    }

    /**
     * A wrong verb is its own mistake and used to be reported as `not_found`,
     * which sent integrators looking for a missing resource instead of a typo
     * in their HTTP method. `Allow` says what the URI does accept.
     */
    public function test_an_unsupported_method_returns_method_not_allowed(): void
    {
        $response = $this->json('DELETE', '/api/v1/networks')
            ->assertStatus(405)
            ->assertJsonStructure(['error' => ['code', 'message', 'details']])
            ->assertJsonPath('error.code', 'method_not_allowed');

        $allow = $response->headers->get('Allow');

        $this->assertNotNull($allow, 'A 405 must carry an Allow header.');
        $this->assertStringContainsString('GET', $allow);
    }

    public function test_every_error_uses_the_spec_envelope(): void
    {
        foreach ([
            ['/api/v1/me', 401, 'unauthenticated'],
            ['/api/public/invoices/00000000-0000-0000-0000-000000000000', 404, 'not_found'],
            ['/api/internal/config', 401, 'unauthenticated'],
        ] as [$uri, $status, $code]) {
            $this->getJson($uri)
                ->assertStatus($status)
                ->assertJsonStructure(['error' => ['code', 'message', 'details']])
                ->assertJsonPath('error.code', $code);
        }
    }
}
