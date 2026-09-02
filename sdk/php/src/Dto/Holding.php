<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * A customer's holding of a merchant token.
 */
final readonly class Holding
{
    /**
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public ?Token $token,
        public string $customerId,
        public string $amount,
        public ?string $updatedAt,
        public array $raw = [],
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            token: isset($data['token']) && is_array($data['token']) ? Token::fromArray($data['token']) : null,
            customerId: (string) ($data['customer_id'] ?? ''),
            amount: (string) ($data['amount'] ?? '0'),
            updatedAt: $data['updated_at'] ?? null,
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
