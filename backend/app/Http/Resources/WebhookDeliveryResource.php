<?php

namespace App\Http\Resources;

use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WebhookDelivery
 */
class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'invoice_id' => $this->invoice_id,
            'event' => $this->event,
            'url' => $this->url,
            'payload' => $this->decodedPayload(),
            'signature' => $this->signature,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'status' => $this->status->value,
            'response_code' => $this->response_code,
            'response_body' => $this->response_body,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
