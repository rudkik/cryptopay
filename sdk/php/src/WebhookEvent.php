<?php

declare(strict_types=1);

namespace CryptoPay\Sdk;

use CryptoPay\Sdk\Dto\Invoice;
use CryptoPay\Sdk\Dto\TokenPurchase;

/**
 * A verified CryptoPay webhook delivery.
 *
 * Body shape: `{"id": delivery_uuid, "event": "invoice.paid", "created_at": ISO,
 * "data": {"invoice": Invoice, "token_purchase": TokenPurchase|null}}`.
 */
final readonly class WebhookEvent
{
    /**
     * @param  array<mixed>  $payload  The raw decoded webhook body.
     */
    public function __construct(
        public string $event,
        public ?string $deliveryId,
        public string $id,
        public ?string $createdAt,
        public ?Invoice $invoice,
        public ?TokenPurchase $tokenPurchase,
        public array $payload,
    ) {
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromArray(array $payload, ?string $eventHeader = null, ?string $deliveryHeader = null): self
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $invoice = isset($data['invoice']) && is_array($data['invoice'])
            ? Invoice::fromArray($data['invoice'])
            : null;

        $tokenPurchase = isset($data['token_purchase']) && is_array($data['token_purchase'])
            ? TokenPurchase::fromArray($data['token_purchase'])
            : null;

        $id = (string) ($payload['id'] ?? '');

        return new self(
            event: (string) ($payload['event'] ?? $eventHeader ?? ''),
            deliveryId: $deliveryHeader ?? ($id !== '' ? $id : null),
            id: $id,
            createdAt: $payload['created_at'] ?? null,
            invoice: $invoice,
            tokenPurchase: $tokenPurchase,
            payload: $payload,
        );
    }

    public function isPaid(): bool
    {
        return in_array($this->event, ['invoice.paid', 'invoice.overpaid'], true);
    }

    /**
     * A reorg (or a failed transaction) took back a payment this invoice had
     * already settled with, and it is no longer paid. Revoke whatever you
     * credited for that invoice; `reversal()` says which transaction went away.
     */
    public function isReversed(): bool
    {
        return $this->event === 'invoice.reversed';
    }

    /**
     * The `data.reversal` block of an `invoice.reversed` delivery:
     * `{transaction_id, tx_hash, amount, reason: 'orphaned'|'failed'}`.
     * Null on every other event.
     *
     * @return array<mixed>|null
     */
    public function reversal(): ?array
    {
        $data = is_array($this->payload['data'] ?? null) ? $this->payload['data'] : [];

        return is_array($data['reversal'] ?? null) ? $data['reversal'] : null;
    }

    public function isInvoiceEvent(): bool
    {
        return str_starts_with($this->event, 'invoice.');
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
