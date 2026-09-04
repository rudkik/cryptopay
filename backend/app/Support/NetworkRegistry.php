<?php

namespace App\Support;

use App\Enums\NetworkCode;
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

    /** @var ?list<array<string, mixed>> */
    private ?array $options = null;

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

    /**
     * Every currency/network pair a payer may pick on an invoice that has none
     * yet (SPEC §6.3 `options`): enabled networks crossed with their enabled
     * token contracts, ordered the way SPEC §2 lists them.
     *
     * @return list<array{network: string, network_name: string, chain_id: ?int, currency: string, confirmations_required: int, standard: string}>
     */
    public function selectionOptions(): array
    {
        return $this->options ??= $this->buildSelectionOptions();
    }

    /**
     * @return list<array{network: string, network_name: string, chain_id: ?int, currency: string, confirmations_required: int, standard: string}>
     */
    private function buildSelectionOptions(): array
    {
        $order = array_flip(array_column(NetworkCode::cases(), 'value'));

        $contracts = TokenContract::query()
            ->where('is_enabled', true)
            ->orderBy('symbol')
            ->get()
            ->groupBy('network_code');

        $networks = Network::query()
            ->where('is_enabled', true)
            ->get()
            // An unknown code sorts last rather than blowing up: networks are
            // rows, and nothing stops an operator adding one.
            ->sortBy(fn (Network $network) => $order[$network->code] ?? PHP_INT_MAX)
            ->values();

        $options = [];

        foreach ($networks as $network) {
            $standard = NetworkCode::standardFor($network->code);

            if ($standard === null) {
                continue;
            }

            foreach ($contracts->get($network->code, collect()) as $contract) {
                $this->networks[$network->code] = $network;
                $this->contracts[$network->code.'/'.$contract->symbol] = $contract;

                $options[] = [
                    'network' => $network->code,
                    'network_name' => $network->name,
                    'chain_id' => $network->chain_id,
                    'currency' => $contract->symbol,
                    'confirmations_required' => (int) $network->confirmations_required,
                    'standard' => $standard,
                ];
            }
        }

        return $options;
    }

    public function flush(): void
    {
        $this->networks = [];
        $this->contracts = [];
        $this->options = null;
    }

    public static function make(): self
    {
        return app(self::class);
    }
}
