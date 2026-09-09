<?php

namespace App\Exceptions;

/**
 * Nothing can hand out an address for a network: no static receiving address
 * accepts the currency, and no xpub exists in `wallets.xpub` or the watcher's
 * env (SPEC §3). The money would have nowhere to go; refusing is the only safe
 * answer.
 */
class WalletNotConfiguredException extends ApiException
{
    public function __construct(string $networkCode)
    {
        parent::__construct(
            'wallet_not_configured',
            "No deposit wallet is configured for [{$networkCode}], so the invoice has nowhere to be paid to. "
                .'An administrator must add a receiving address for this network and currency (Admin → Addresses) '
                .'or set an xpub for the network (Admin → Wallet).',
            503,
            ['network' => [$networkCode]],
        );
    }
}
