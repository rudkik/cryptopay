<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * A merchant-defined token (used for token-sale invoices).
 */
final readonly class Token
{
    /**
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $merchantId,
        public string $symbol,
        public string $name,
        public ?string $description,
        public string $priceUsd,
        public int $decimals,
        public ?string $totalSupply,
        public string $sold,
        public ?string $minPurchase,
        public ?string $maxPurchase,
        public bool $isActive,
        public ?string $imageUrl,
        public ?string $createdAt,
        public array $raw = [],
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            merchantId: $data['merchant_id'] ?? null,
            symbol: (string) ($data['symbol'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            description: $data['description'] ?? null,
            priceUsd: (string) ($data['price_usd'] ?? '0'),
            decimals: (int) ($data['decimals'] ?? 18),
            totalSupply: $data['total_supply'] ?? null,
            sold: (string) ($data['sold'] ?? '0'),
            minPurchase: $data['min_purchase'] ?? null,
            maxPurchase: $data['max_purchase'] ?? null,
            isActive: (bool) ($data['is_active'] ?? false),
            imageUrl: $data['image_url'] ?? null,
            createdAt: $data['created_at'] ?? null,
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
