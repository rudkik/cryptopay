<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * A merchant's balance for one currency/network pair. Amounts are decimal strings.
 */
final readonly class Balance
{
    /**
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public string $currency,
        public string $network,
        public string $available,
        public string $pending,
        public array $raw = [],
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            currency: (string) ($data['currency'] ?? ''),
            network: (string) ($data['network'] ?? ''),
            available: (string) ($data['available'] ?? '0'),
            pending: (string) ($data['pending'] ?? '0'),
            raw: $data,
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
