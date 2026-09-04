<?php

namespace Tests;

use App\Enums\NetworkCode;
use App\Models\Merchant;
use App\Models\User;
use App\Services\ApiKeyService;
use App\Services\WalletService;
use Database\Seeders\NetworkSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function seedNetworks(): void
    {
        $this->seed(NetworkSeeder::class);
    }

    /**
     * Discard every registered HTTP stub. Http::fake() merges into the existing
     * stubs rather than replacing them, so a test that needs a different
     * watcher response has to start from a clean factory.
     *
     * The watcher's /health stub is immediately re-registered: it is not a
     * behaviour under test, it is the ambient fact that the watcher has its
     * env xpubs loaded, without which every network looks unconfigured and
     * invoices are rejected before the code under test is reached. A test that
     * *wants* an unconfigured wallet says so with `fakeWatcher(false, false)`.
     */
    protected function resetHttpFakes(): void
    {
        Http::swap(new Factory($this->app['events']));
        Http::fake(['*/health' => Http::response($this->watcherHealthBody())]);
    }

    /** @return array<string, mixed> */
    protected function watcherHealthBody(bool $evm = true, bool $tron = true): array
    {
        return [
            'ok' => true,
            'derivationReady' => $evm || $tron,
            'derivation' => ['evm' => $evm, 'tron' => $tron],
            'networks' => [],
        ];
    }

    /**
     * Fake the watcher's derivation endpoints and /health, plus a catch-all so
     * outgoing webhooks are captured instead of hitting the network.
     *
     * /health matters as much as the derivation stub: WalletService reads
     * `derivation.evm` / `derivation.tron` from it to decide whether a network
     * without a stored xpub can still fall back to the watcher's env key, and
     * a network that can do neither is hidden from every currency/network
     * picker (SPEC §3).
     */
    protected function fakeWatcher(bool $evmDerivation = true, bool $tronDerivation = true): void
    {
        // A full reset rather than a merge: Http::fake() keeps the *first*
        // stub registered for a URL, so re-faking /health on top of an
        // existing stub would silently keep the old answer.
        Http::swap(new Factory($this->app['events']));
        $this->forgetWalletCache();

        Http::fake([
            '*/health' => Http::response($this->watcherHealthBody($evmDerivation, $tronDerivation)),
            '*/addresses/derive-batch' => function (ClientRequest $request) {
                $data = $request->data();
                $network = $data['network'];
                $from = (int) ($data['from'] ?? 0);
                $count = (int) ($data['count'] ?? 1);

                return Http::response([
                    'addresses' => array_map(fn (int $i) => [
                        'index' => $i,
                        'path' => $this->derivationPath($network, $i),
                        'address' => $this->fakeAddress($network, $i, $data['xpub'] ?? null),
                    ], range($from, $from + $count - 1)),
                ]);
            },
            '*/addresses/derive' => function (ClientRequest $request) {
                $data = $request->data();
                $network = $data['network'];
                $index = (int) $data['index'];

                return Http::response([
                    'network' => $network,
                    'index' => $index,
                    'path' => $this->derivationPath($network, $index),
                    'address' => $this->fakeAddress($network, $index, $data['xpub'] ?? null),
                ]);
            },
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    protected function derivationPath(string $network, int $index): string
    {
        return $network === 'tron' ? "m/44'/195'/0'/0/{$index}" : "m/44'/60'/0'/0/{$index}";
    }

    /**
     * Deterministic stand-in for a derived address. The xpub (when the caller
     * passes one) is part of the seed, so a test can assert that changing the
     * key changes the addresses — exactly as it would on the real watcher.
     */
    protected function fakeAddress(string $network, int $index, ?string $xpub = null): string
    {
        $seed = md5($network.':'.$index.':'.($xpub ?? 'env'));

        return $network === 'tron'
            ? 'T'.mb_strtoupper(substr($seed, 0, 33))
            : '0x'.substr($seed, 0, 40);
    }

    /** Drop the 30s memo of "which networks have a wallet" (SPEC §3). */
    protected function forgetWalletCache(): void
    {
        app(WalletService::class)->forget();
    }

    /** @return array{0: Merchant, 1: string} merchant and its plaintext API key */
    protected function makeMerchant(array $attributes = []): array
    {
        $merchant = Merchant::factory()->create($attributes);

        [, $plaintext] = app(ApiKeyService::class)->generate($merchant, 'Test key');

        return [$merchant, $plaintext];
    }

    /** @return array<string, string> */
    protected function keyHeaders(string $plaintext, array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer '.$plaintext,
            'Accept' => 'application/json',
        ], $extra);
    }

    /** @return array<string, string> */
    protected function internalHeaders(array $extra = []): array
    {
        return array_merge([
            'X-Internal-Token' => (string) config('services.internal.token'),
            'Accept' => 'application/json',
        ], $extra);
    }

    /**
     * A transaction hash in the shape the internal API requires: `0x` + 64 hex
     * on EVM chains, bare 64 hex on Tron.
     */
    protected function txHash(string $seed, string $network = 'tron'): string
    {
        $hex = hash('sha256', $seed);

        return NetworkCode::isEvmCode($network) ? '0x'.$hex : $hex;
    }

    /**
     * The API uses the SPEC §6 envelope, so validation failures land in
     * `error.details` rather than PHPUnit's expected `errors` key.
     */
    protected function assertInvalidField(TestResponse $response, string $field): void
    {
        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $this->assertArrayHasKey(
            $field,
            (array) $response->json('error.details'),
            "Expected a validation error for [{$field}]; got: ".json_encode($response->json('error.details')),
        );
    }

    /**
     * Laravel resolves the auth guard once per application instance, and a
     * feature test reuses one instance across every request it makes. Without
     * this, the second request in a test silently keeps the first request's
     * user — which quietly defeats any test about revocation, deactivation or
     * "acting as someone else".
     */
    protected function forgetAuth(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    protected function adminToken(?User $user = null): string
    {
        $user ??= User::factory()->create();

        return $user->createToken('test')->plainTextToken;
    }
}
