<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Token;
use App\Services\ApiKeyService;
use Illuminate\Database\Seeder;

/**
 * A ready-to-use demo merchant so the stack is usable the moment it boots.
 *
 * In local/testing the API key plaintext is deterministic (DEMO_API_KEY, or the
 * documented default) so the frontend and the docs can hard-code it. In any
 * other environment a random key is generated instead.
 *
 * Idempotent: rows are matched by name/symbol and only created when missing.
 */
class DemoMerchantSeeder extends Seeder
{
    public const DEFAULT_KEY = ApiKeyService::PREFIX.'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';

    public function run(): void
    {
        $apiKeys = app(ApiKeyService::class);

        $merchant = Merchant::query()->firstOrCreate(
            ['name' => 'Demo Shop'],
            [
                'email' => 'demo@cryptopay.local',
                'webhook_url' => null,
                'webhook_secret' => ApiKeyService::generateWebhookSecret(),
                'is_active' => true,
                'settings' => ['underpayment_tolerance' => 0.5],
            ],
        );

        $plaintext = $this->demoKey();

        if (! $apiKeys->resolve($plaintext)) {
            $existing = $merchant->apiKeys()->where('name', 'Demo key')->exists();

            if (! $existing) {
                $apiKeys->generate($merchant, 'Demo key', $plaintext);

                // Outside local/testing this is a randomly generated, fully
                // working credential; printing it would write it to the
                // container log on every boot.
                if (app()->environment(['local', 'testing'])) {
                    $this->command?->info("Demo API key: {$plaintext}");
                } else {
                    $this->command?->info('Demo API key created; issue a fresh one from the admin panel to see it.');
                }
            }
        }

        Token::query()->firstOrCreate(
            ['merchant_id' => $merchant->id, 'symbol' => 'DEMO'],
            [
                'name' => 'Demo Token',
                'description' => 'A sample token sale product for trying out the token purchase flow.',
                'price_usd' => '0.25',
                'decimals' => 18,
                'total_supply' => '1000000',
                'sold' => '0',
                'min_purchase' => '1',
                'max_purchase' => '100000',
                'is_active' => true,
            ],
        );
    }

    /**
     * `cp_live_` + exactly 40 hex characters. Falls back to a random key outside
     * local/testing so a public deployment never ships a known credential.
     */
    private function demoKey(): string
    {
        $configured = (string) env('DEMO_API_KEY', '');

        if ($configured !== '' && app(ApiKeyService::class)->looksValid($configured)) {
            return $configured;
        }

        if (! app()->environment(['local', 'testing'])) {
            return ApiKeyService::PREFIX.bin2hex(random_bytes(20));
        }

        return self::DEFAULT_KEY;
    }
}
