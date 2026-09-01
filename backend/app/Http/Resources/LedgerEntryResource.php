<?php

namespace App\Http\Resources;

use App\Models\LedgerEntry;
use App\Support\Money;
use App\Support\NetworkRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $decimals = NetworkRegistry::make()->decimals($this->network_code, $this->currency);

        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'currency' => $this->currency,
            'network' => $this->network_code,
            'amount' => Money::format($this->amount, $decimals),
            'type' => $this->type->value,
            'transaction_id' => $this->transaction_id,
            'balance_after' => Money::format($this->balance_after, $decimals),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
