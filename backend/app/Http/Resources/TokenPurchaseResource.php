<?php

namespace App\Http\Resources;

use App\Models\TokenPurchase;
use App\Support\Money;
use App\Support\NetworkRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TokenPurchase
 */
class TokenPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('token');
        $tokenDecimals = $this->token?->decimals ?? 18;
        $payDecimals = NetworkRegistry::make()->decimals($this->invoice?->network_code, $this->currency);

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'token_id' => $this->token_id,
            'merchant_id' => $this->merchant_id,
            'customer_id' => $this->customer_id,
            'customer_email' => $this->customer_email,
            'token_amount' => Money::format($this->token_amount, $tokenDecimals),
            'price_usd' => Money::trim($this->price_usd),
            'pay_amount' => Money::format($this->pay_amount, $payDecimals),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'token' => $this->token ? (new TokenResource($this->token))->toArray($request) : null,
        ];
    }
}
