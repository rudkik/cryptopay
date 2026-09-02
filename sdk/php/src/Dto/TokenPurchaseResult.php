<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * The response of POST /api/v1/token-purchases: `{purchase, invoice}`,
 * NOT wrapped in a `data` envelope like every other endpoint.
 */
final readonly class TokenPurchaseResult
{
    /**
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public TokenPurchase $purchase,
        public Invoice $invoice,
        public array $raw = [],
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            purchase: TokenPurchase::fromArray((array) ($data['purchase'] ?? [])),
            invoice: Invoice::fromArray((array) ($data['invoice'] ?? [])),
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
