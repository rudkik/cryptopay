<?php

namespace App\Exceptions;

/**
 * Every receiving address configured for this network/currency is currently
 * leased by an open invoice and no HD xpub exists to derive a fresh one from.
 * Refusing is safer than handing out an address a second invoice already
 * expects money on.
 */
class NoFreeAddressException extends ApiException
{
    public function __construct(string $networkCode, string $currency)
    {
        parent::__construct(
            'no_free_address',
            "Every receiving address for {$currency} on [{$networkCode}] is busy with an open invoice. "
                .'Add more addresses on the admin Addresses page, or wait for an invoice to finish.',
            503,
            ['network' => [$networkCode], 'currency' => [$currency]],
        );
    }
}
