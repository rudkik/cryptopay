<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Confirming = 'confirming';
    case Paid = 'paid';
    case Overpaid = 'overpaid';
    case PartiallyPaid = 'partially_paid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isPaid(): bool
    {
        return in_array($this, [self::Paid, self::Overpaid], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Confirming], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Overpaid, self::Cancelled], true);
    }

    /** Webhook event name emitted when an invoice enters this status. */
    public function webhookEvent(): string
    {
        return 'invoice.'.$this->value;
    }

    /**
     * `pending` is the initial state and is never announced; every other
     * transition maps to one of the SPEC §6.2 events.
     */
    public function emitsWebhook(): bool
    {
        return $this !== self::Pending;
    }
}
