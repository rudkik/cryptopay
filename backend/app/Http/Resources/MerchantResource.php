<?php

namespace App\Http\Resources;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Merchant
 */
class MerchantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'webhook_url' => $this->webhook_url,
            'is_active' => $this->is_active,
            'settings' => (object) ($this->settings ?? []),
            'underpayment_tolerance' => $this->underpaymentTolerance(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Closure form: passing a MissingValue straight into ::collection()
            // blows up before the resource can filter it out.
            'balances' => $this->whenLoaded('balances', fn () => BalanceResource::collection($this->balances)->resolve()),
            'api_keys' => $this->whenLoaded('apiKeys', fn () => ApiKeyResource::collection($this->apiKeys)->resolve()),
        ];
    }
}
