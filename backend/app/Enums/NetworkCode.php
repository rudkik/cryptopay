<?php

namespace App\Enums;

enum NetworkCode: string
{
    case Ethereum = 'ethereum';
    case Bsc = 'bsc';
    case Tron = 'tron';

    public function isEvm(): bool
    {
        return $this !== self::Tron;
    }

    /** The token standard payers recognise this network by (SPEC §2). */
    public function standard(): string
    {
        return match ($this) {
            self::Ethereum => 'ERC-20',
            self::Bsc => 'BEP-20',
            self::Tron => 'TRC-20',
        };
    }

    /** Same, addressed by raw code; null for a network we do not know. */
    public static function standardFor(?string $code): ?string
    {
        return $code === null ? null : self::tryFrom($code)?->standard();
    }

    public static function isEvmCode(string $code): bool
    {
        return $code !== self::Tron->value;
    }
}
