<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * The response of GET /api/v1/balances: per (currency, network) balances
 * plus per-currency totals.
 */
final readonly class Balances
{
    /**
     * @param  Balance[]  $data
     * @param  array<string, array{available: string, pending: string}>  $totals  Raw totals keyed by currency.
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public array $data,
        public array $totals,
        public array $raw = [],
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $balances = [];
        foreach ((array) ($data['data'] ?? []) as $balance) {
            if (is_array($balance)) {
                $balances[] = Balance::fromArray($balance);
            }
        }

        return new self(
            data: $balances,
            totals: is_array($data['totals'] ?? null) ? $data['totals'] : [],
            raw: $data,
        );
    }

    /**
     * The total available/pending for a currency across all networks.
     *
     * @return array{available: string, pending: string}
     */
    public function total(string $currency): array
    {
        $total = $this->totals[$currency] ?? null;

        if (! is_array($total)) {
            return ['available' => '0', 'pending' => '0'];
        }

        return [
            'available' => (string) ($total['available'] ?? '0'),
            'pending' => (string) ($total['pending'] ?? '0'),
        ];
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
