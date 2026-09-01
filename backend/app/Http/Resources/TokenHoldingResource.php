<?php

namespace App\Http\Resources;

use App\Models\TokenHolding;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TokenHolding
 */
class TokenHoldingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('token');

        return [
            'token' => $this->token ? (new TokenResource($this->token))->toArray($request) : null,
            'customer_id' => $this->customer_id,
            'amount' => Money::format($this->amount, $this->token?->decimals ?? 18),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
