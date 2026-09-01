<?php

namespace Tests;

use App\Models\Merchant;
use App\Models\User;
use App\Services\ApiKeyService;
use Database\Seeders\NetworkSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

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
     */
    protected function resetHttpFakes(): void
    {
        Http::swap(new Factory($this->app['events']));
    }

    /**
     * Fake the watcher's derivation endpoint plus a catch-all so outgoing
     * webhooks are captured instead of hitting the network.
     */
    protected function fakeWatcher(): void
    {
        Http::fake([
            '*/addresses/derive' => function (ClientRequest $request) {
                $data = $request->data();
                $network = $data['network'];
                $index = (int) $data['index'];
                $seed = md5($network.':'.$index);

                return Http::response([
                    'network' => $network,
                    'index' => $index,
                    'path' => $network === 'tron'
                        ? "m/44'/195'/0'/0/{$index}"
                        : "m/44'/60'/0'/0/{$index}",
                    'address' => $network === 'tron'
                        ? 'T'.mb_strtoupper(substr($seed, 0, 33))
                        : '0x'.substr($seed, 0, 40),
                ]);
            },
            '*' => Http::response(['ok' => true], 200),
        ]);
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

    protected function adminToken(?User $user = null): string
    {
        $user ??= User::factory()->create();

        return $user->createToken('test')->plainTextToken;
    }
}
