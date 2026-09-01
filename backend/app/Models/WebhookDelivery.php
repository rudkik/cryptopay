<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id', 'invoice_id', 'event', 'url', 'payload', 'signature',
        'attempts', 'max_attempts', 'status', 'response_code', 'response_body',
        'next_attempt_at', 'delivered_at', 'last_error',
    ];

    protected function casts(): array
    {
        // `payload` is deliberately NOT cast: it holds the exact JSON bytes that
        // were signed and sent, and any decode/encode round trip could change
        // them (`{}` -> `[]`, key order, escaping) and invalidate the signature.
        return [
            'status' => WebhookDeliveryStatus::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'response_code' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The raw JSON body, exactly as signed and sent. */
    public function body(): string
    {
        return (string) $this->payload;
    }

    public function decodedPayload(): ?object
    {
        $decoded = json_decode((string) $this->payload);

        return is_object($decoded) ? $decoded : null;
    }
}
