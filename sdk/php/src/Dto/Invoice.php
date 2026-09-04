<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * A CryptoPay invoice (payment request).
 *
 * Amounts (`amount`, `amountReceived`, `amountConfirmed`) are decimal
 * strings — never cast them to float, use bcmath/GMP for arithmetic.
 *
 * An invoice created without a currency/network pair comes back with
 * `currency`, `network`, `address` and `qrPayload` all null and
 * `selectionRequired` true — the payer picks the pair on the hosted
 * payment page (or the merchant calls `Client::selectInvoiceNetwork()`).
 */
final readonly class Invoice
{
    /**
     * @param  array<mixed>  $metadata
     * @param  Transaction[]  $transactions
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $externalId,
        public string $status,
        public bool $isPaid,
        public ?string $currency,
        public ?string $network,
        public bool $selectionRequired,
        public string $amount,
        public string $amountReceived,
        public string $amountConfirmed,
        public ?string $address,
        public ?string $paymentUrl,
        public ?string $qrPayload,
        public ?string $description,
        public ?string $customerEmail,
        public ?string $customerId,
        public array $metadata,
        public ?string $successUrl,
        public ?string $cancelUrl,
        public ?string $expiresAt,
        public ?string $paidAt,
        public ?string $createdAt,
        public array $transactions,
        public ?TokenPurchase $tokenPurchase,
        public array $raw = [],
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $transactions = [];
        foreach ((array) ($data['transactions'] ?? []) as $transaction) {
            if (is_array($transaction)) {
                $transactions[] = Transaction::fromArray($transaction);
            }
        }

        $tokenPurchase = null;
        if (isset($data['token_purchase']) && is_array($data['token_purchase'])) {
            $tokenPurchase = TokenPurchase::fromArray($data['token_purchase']);
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            externalId: $data['external_id'] ?? null,
            status: (string) ($data['status'] ?? ''),
            isPaid: (bool) ($data['is_paid'] ?? false),
            currency: $data['currency'] ?? null,
            network: $data['network'] ?? null,
            selectionRequired: (bool) ($data['selection_required'] ?? false),
            amount: (string) ($data['amount'] ?? '0'),
            amountReceived: (string) ($data['amount_received'] ?? '0'),
            amountConfirmed: (string) ($data['amount_confirmed'] ?? '0'),
            address: $data['address'] ?? null,
            paymentUrl: $data['payment_url'] ?? null,
            qrPayload: $data['qr_payload'] ?? null,
            description: $data['description'] ?? null,
            customerEmail: $data['customer_email'] ?? null,
            customerId: $data['customer_id'] ?? null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            successUrl: $data['success_url'] ?? null,
            cancelUrl: $data['cancel_url'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            paidAt: $data['paid_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            transactions: $transactions,
            tokenPurchase: $tokenPurchase,
            raw: $data,
        );
    }

    public function isPaid(): bool
    {
        return $this->isPaid;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    /** The payer (or the merchant) still has to pick a currency/network pair. */
    public function needsSelection(): bool
    {
        return $this->selectionRequired;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
