<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * Metadata of a merchant API key. The key itself is shown once at creation
 * time and never returned again — only its 12-character prefix.
 */
final readonly class ApiKey
{
    /**
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $merchantId,
        public ?string $name,
        public ?string $keyPrefix,
        public ?string $lastUsedAt,
        public ?string $revokedAt,
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
            name: $data['name'] ?? null,
            keyPrefix: $data['key_prefix'] ?? null,
            lastUsedAt: $data['last_used_at'] ?? null,
            revokedAt: $data['revoked_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            raw: $data,
        );
    }

    /** A key is usable only while it has not been revoked. */
    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
