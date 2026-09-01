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

    public static function isEvmCode(string $code): bool
    {
        return $code !== self::Tron->value;
    }
}
