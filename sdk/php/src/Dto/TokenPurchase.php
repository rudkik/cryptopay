<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * A customer's purchase of a merchant token.
 *
 * `currency` is null while the purchase invoice is still waiting for the
 * payer to pick a currency/network pair — see {@see Invoice}.
 */
final readonly class TokenPurchase
{
    /**
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $invoiceId,
        public string $tokenId,
        public ?string $merchantId,
        public string $customerId,
        public ?string $customerEmail,
        public string $tokenAmount,
        public string $priceUsd,
        public string $payAmount,
        public ?string $currency,
        public string $status,
        public ?string $completedAt,
        public ?string $createdAt,
        public ?Token $token,
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
            invoiceId: $data['invoice_id'] ?? null,
            tokenId: (string) ($data['token_id'] ?? ''),
            merchantId: $data['merchant_id'] ?? null,
            customerId: (string) ($data['customer_id'] ?? ''),
            customerEmail: $data['customer_email'] ?? null,
            tokenAmount: (string) ($data['token_amount'] ?? '0'),
            priceUsd: (string) ($data['price_usd'] ?? '0'),
            payAmount: (string) ($data['pay_amount'] ?? '0'),
            currency: $data['currency'] ?? null,
            status: (string) ($data['status'] ?? ''),
            completedAt: $data['completed_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            token: isset($data['token']) && is_array($data['token']) ? Token::fromArray($data['token']) : null,
            raw: $data,
        );
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
