<?php

namespace App\Services;

use App\Enums\WebhookDeliveryStatus;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\TokenPurchaseResource;
use App\Jobs\SendWebhookJob;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\TokenPurchase;
use App\Models\WebhookDelivery;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Outgoing webhooks (SPEC §6.2).
 *
 * The exact bytes that get signed are the bytes that get sent: the JSON body is
 * encoded once at creation time and stored verbatim in `payload`, so a retry
 * days later re-sends and re-signs the identical body.
 */
class WebhookService
{
    public const QUEUE = 'webhooks';

    /**
     * Emitted when a reorg (or a failed transaction) takes back money an
     * invoice had already settled with, so the merchant can revoke whatever it
     * credited for that invoice. It is not an InvoiceStatus: the invoice lands
     * back on `pending`/`confirming`/`expired`, and the event is what carries
     * the news that it got there by losing a payment.
     */
    public const INVOICE_REVERSED = 'invoice.reversed';

    /**
     * Every event the API can emit (SPEC §6.2), in lifecycle order. Declared
     * once, here, next to the code that sends them: `GET /api/v1/me`
     * advertises the same list to merchants.
     */
    public const EVENTS = [
        'invoice.confirming',
        'invoice.paid',
        'invoice.overpaid',
        'invoice.partially_paid',
        self::INVOICE_REVERSED,
        'invoice.expired',
        'invoice.cancelled',
        'token_purchase.completed',
    ];

    /** Retry backoff in seconds, applied after a failed attempt (SPEC §6.2). */
    public const RETRY_DELAYS = [60, 300, 1800, 7200, 21600, 86400];

    public const MAX_ATTEMPTS = 6;

    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Queue a webhook for an invoice-scoped event. Returns null when the
     * merchant has no webhook URL configured.
     *
     * `$extra` is merged into `data` after the standard keys, for the few
     * events that carry more than the invoice itself (`invoice.reversed` adds
     * a `reversal` block).
     *
     * @param  array<string, mixed>  $extra
     */
    public function dispatchInvoiceEvent(string $event, Invoice $invoice, ?TokenPurchase $purchase = null, array $extra = []): ?WebhookDelivery
    {
        $invoice->loadMissing(['merchant', 'depositAddress']);
        $merchant = $invoice->merchant;

        if (! $merchant instanceof Merchant) {
            return null;
        }

        $purchase ??= $invoice->tokenPurchase()->first();

        $data = [
            'invoice' => $this->toPlainArray(new InvoiceResource($invoice)),
            'token_purchase' => $purchase ? $this->toPlainArray(new TokenPurchaseResource($purchase)) : null,
        ] + $extra;

        return $this->create($merchant, $event, $data, $invoice);
    }

    public function create(Merchant $merchant, string $event, array $data, ?Invoice $invoice = null): ?WebhookDelivery
    {
        $url = trim((string) $merchant->webhook_url);

        if ($url === '') {
            return null;
        }

        $id = (string) Str::uuid();

        $body = json_encode([
            'id' => $id,
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => $data,
        ], self::JSON_FLAGS);

        $delivery = new WebhookDelivery;
        $delivery->id = $id;
        $delivery->forceFill([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice?->id,
            'event' => $event,
            'url' => $url,
            'payload' => $body,
            'signature' => null,
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'status' => WebhookDeliveryStatus::Pending->value,
            'next_attempt_at' => now(),
        ])->save();

        SendWebhookJob::dispatch($delivery->id)->onQueue(self::QUEUE);

        return $delivery;
    }

    /** `sha256=<hex hmac_sha256(secret, timestamp + "." + raw_body)>` (SPEC §6.2). */
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Re-queue a delivery from the admin UI or the retry scheduler. */
    public function retry(WebhookDelivery $delivery): WebhookDelivery
    {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Pending->value,
            'next_attempt_at' => now(),
        ])->save();

        SendWebhookJob::dispatch($delivery->id)->onQueue(self::QUEUE);

        return $delivery;
    }

    /**
     * Resolve a resource down to plain arrays/scalars so the encoded body is
     * stable and contains no lazily-resolved resource objects.
     */
    private function toPlainArray(JsonResource $resource): array
    {
        return json_decode(json_encode($resource->toArray(request()), self::JSON_FLAGS), true);
    }
}
