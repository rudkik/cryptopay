<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * A blockchain transaction detected/confirmed against an invoice's address.
 */
final readonly class Transaction
{
    /**
     * @param  array<mixed>  $raw  The original raw payload, for round-tripping via toArray().
     */
    public function __construct(
        public string $id,
        public ?string $invoiceId,
        public string $network,
        public string $txHash,
        public int $logIndex,
        public ?string $fromAddress,
        public ?string $toAddress,
        public string $currency,
        public ?string $contractAddress,
        public string $amount,
        public ?string $amountRaw,
        public ?int $blockNumber,
        public int $confirmations,
        public int $confirmationsRequired,
        public string $status,
        public ?string $explorerUrl,
        public ?string $creditedAt,
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
            invoiceId: $data['invoice_id'] ?? null,
            network: (string) ($data['network'] ?? ''),
            txHash: (string) ($data['tx_hash'] ?? ''),
            logIndex: (int) ($data['log_index'] ?? 0),
            fromAddress: $data['from_address'] ?? null,
            toAddress: $data['to_address'] ?? null,
            currency: (string) ($data['currency'] ?? ''),
            contractAddress: $data['contract_address'] ?? null,
            amount: (string) ($data['amount'] ?? '0'),
            amountRaw: $data['amount_raw'] ?? null,
            blockNumber: isset($data['block_number']) ? (int) $data['block_number'] : null,
            confirmations: (int) ($data['confirmations'] ?? 0),
            confirmationsRequired: (int) ($data['confirmations_required'] ?? 0),
            status: (string) ($data['status'] ?? ''),
            explorerUrl: $data['explorer_url'] ?? null,
            creditedAt: $data['credited_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            raw: $data,
        );
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
