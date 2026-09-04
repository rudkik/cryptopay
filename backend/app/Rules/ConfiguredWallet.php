<?php

namespace App\Rules;

use App\Services\WalletService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A network may only be requested explicitly if a deposit wallet exists for it
 * (SPEC §3): an xpub stored on the wallet row, or the watcher's env fallback.
 * Without one there is nowhere to derive an address, so the invoice would be
 * created and then fail at allocation time — reject it as a field error while
 * the caller can still pick another chain.
 */
class ConfiguredWallet implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (! app(WalletService::class)->isConfigured($value)) {
            $fail("No deposit wallet is configured for [{$value}], so it cannot accept payments right now. Pick another network, or ask an administrator to set its xpub on the admin Wallet page.");
        }
    }
}
