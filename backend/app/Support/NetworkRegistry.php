<?php

namespace App\Support;

use App\Models\Network;
use App\Models\TokenContract;

/**
 * Request-scoped memo for networks and token contracts. Registered as a
 * singleton, so it is naturally fresh for every request/test boot; these rows
 * change only through the admin API.
 */
class NetworkRegistry
{
    /** @var array<string, Network|null> */
    private array $networks = [];

    /** @var array<string, TokenContract|null> */
    private array $contracts = [];

    public function network(?string $code): ?Network
    {
        if ($code === null) {
            return null;
        }

        return $this->networks[$code] ??= Network::query()->where('code', $code)->first();
    }

    public function contract(?string $networkCode, ?string $symbol): ?TokenContract
    {
        if ($networkCode === null || $symbol === null) {
            return null;
        }

        $key = $networkCode.'/'.$symbol;

        return $this->contracts[$key] ??= TokenContract::query()
            ->where('network_code', $networkCode)
            ->where('symbol', $symbol)
            ->first();
    }

    public function decimals(?string $networkCode, ?string $symbol): int
    {
        return $this->contract($networkCode, $symbol)?->decimals ?? 6;
    }

    public function confirmationsRequired(?string $networkCode): int
    {
        return $this->network($networkCode)?->confirmations_required ?? 0;
    }

    public function flush(): void
    {
        $this->networks = [];
        $this->contracts = [];
    }

    public static function make(): self
    {
        return app(self::class);
    }
}
