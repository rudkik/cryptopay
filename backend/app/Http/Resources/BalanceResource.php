<?php

namespace App\Http\Resources;

use App\Models\Balance;
use App\Support\Money;
use App\Support\NetworkRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Balance
 */
class BalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $decimals = NetworkRegistry::make()->decimals($this->network_code, $this->currency);

        return [
            'currency' => $this->currency,
            'network' => $this->network_code,
            'available' => Money::format($this->available, $decimals),
            'pending' => Money::format($this->pending, $decimals),
        ];
    }
}
