<?php

namespace App\Http\Resources;

use App\Models\Token;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Token
 */
class TokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'symbol' => $this->symbol,
            'name' => $this->name,
            'description' => $this->description,
            'price_usd' => Money::trim($this->price_usd),
            'decimals' => $this->decimals,
            'total_supply' => $this->total_supply === null ? null : Money::format($this->total_supply, $this->decimals),
            'sold' => Money::format($this->sold, $this->decimals),
            'min_purchase' => Money::format($this->min_purchase, $this->decimals),
            'max_purchase' => $this->max_purchase === null ? null : Money::format($this->max_purchase, $this->decimals),
            'is_active' => $this->is_active,
            'image_url' => $this->image_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
