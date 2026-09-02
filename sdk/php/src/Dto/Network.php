<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * A supported blockchain network and its enabled tokens.
 */
final readonly class Network
{
    /**
     * @param  array<int, array{symbol: string, contract_address: ?string, decimals: int}>  $tokens
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public string $code,
        public string $name,
        public int|string|null $chainId,
        public int $confirmationsRequired,
        public array $tokens,
        public array $raw = [],
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: (string) ($data['code'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            chainId: $data['chain_id'] ?? null,
            confirmationsRequired: (int) ($data['confirmations_required'] ?? 0),
            tokens: (array) ($data['tokens'] ?? []),
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
