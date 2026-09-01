<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Merchant;
use Illuminate\Support\Str;

/**
 * API keys are `cp_live_<40 hex>`. Only the sha256 hash and the 12-character
 * prefix are stored; the plaintext is shown once at creation (SPEC §4, §6.4).
 */
class ApiKeyService
{
    public const PREFIX = 'cp_live_';

    public const HEX_LENGTH = 40;

    /**
     * @return array{0: ApiKey, 1: string} the model and the one-time plaintext
     */
    public function generate(Merchant $merchant, string $name, ?string $plaintext = null): array
    {
        $plaintext ??= self::PREFIX.bin2hex(random_bytes(self::HEX_LENGTH / 2));

        $apiKey = ApiKey::create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'key_prefix' => substr($plaintext, 0, 12),
            'key_hash' => $this->hash($plaintext),
        ]);

        return [$apiKey, $plaintext];
    }

    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /** Resolve a plaintext key from the Bearer header to a live ApiKey. */
    public function resolve(string $plaintext): ?ApiKey
    {
        $plaintext = trim($plaintext);

        if (! $this->looksValid($plaintext)) {
            return null;
        }

        return ApiKey::query()
            ->with('merchant')
            ->where('key_hash', $this->hash($plaintext))
            ->whereNull('revoked_at')
            ->first();
    }

    public function touch(ApiKey $apiKey): void
    {
        // Avoid a write on every single request; a minute of granularity is plenty.
        if ($apiKey->last_used_at && $apiKey->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    public function revoke(ApiKey $apiKey): ApiKey
    {
        if (! $apiKey->revoked_at) {
            $apiKey->forceFill(['revoked_at' => now()])->save();
        }

        return $apiKey;
    }

    public function looksValid(string $plaintext): bool
    {
        return (bool) preg_match('/^'.preg_quote(self::PREFIX, '/').'[0-9a-f]{'.self::HEX_LENGTH.'}$/', $plaintext);
    }

    public static function generateWebhookSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}
