<?php

namespace App\Exceptions;

/**
 * No xpub is available for a network — neither in `wallets.xpub` nor in the
 * watcher's env (SPEC §3). Deposit addresses cannot be derived, so the money
 * would have nowhere to go; refusing is the only safe answer.
 */
class WalletNotConfiguredException extends ApiException
{
    public function __construct(string $networkCode)
    {
        parent::__construct(
            'wallet_not_configured',
            "No deposit wallet is configured for [{$networkCode}], so an address cannot be derived. "
                .'An administrator must set an xpub for this network on the admin Wallet page (Admin → Wallet).',
            503,
            ['network' => [$networkCode]],
        );
    }
}
