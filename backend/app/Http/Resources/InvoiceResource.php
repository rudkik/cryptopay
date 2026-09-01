<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The canonical Invoice object (SPEC §6.1) — identical everywhere it appears.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $decimals = $this->decimals();

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'external_id' => $this->external_id,
            'status' => $this->status->value,
            'is_paid' => $this->status->isPaid(),
            'currency' => $this->currency,
            'network' => $this->network_code,
            'amount' => Money::format($this->amount, $decimals),
            'amount_received' => Money::format($this->amount_received, $decimals),
            'amount_confirmed' => Money::format($this->amount_confirmed, $decimals),
            'address' => $this->depositAddress?->address,
            'payment_url' => rtrim((string) config('app.url'), '/').'/pay/'.$this->id,
            'qr_payload' => $this->qrPayload(),
            'description' => $this->description,
            'customer_email' => $this->customer_email,
            'customer_id' => $this->customer_id,
            'metadata' => (object) ($this->metadata ?? []),
            'success_url' => $this->success_url,
            'cancel_url' => $this->cancel_url,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'transactions' => TransactionResource::collection(
                $this->relationLoaded('transactions') ? $this->transactions : $this->transactions()->orderBy('created_at')->get()
            ),
            'token_purchase' => $this->whenLoadedOrFetchedTokenPurchase(),
        ];
    }

    private function whenLoadedOrFetchedTokenPurchase(): ?array
    {
        $purchase = $this->relationLoaded('tokenPurchase')
            ? $this->tokenPurchase
            : $this->tokenPurchase()->first();

        return $purchase ? (new TokenPurchaseResource($purchase))->toArray(request()) : null;
    }
}
