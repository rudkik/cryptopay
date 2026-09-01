<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use App\Support\Money;
use App\Support\NetworkRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $registry = NetworkRegistry::make();
        $network = $registry->network($this->network_code);
        $decimals = $registry->decimals($this->network_code, $this->currency);

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'network' => $this->network_code,
            'tx_hash' => $this->tx_hash,
            'log_index' => $this->log_index,
            'from_address' => $this->from_address,
            'to_address' => $this->to_address,
            'currency' => $this->currency,
            'contract_address' => $this->contract_address,
            'amount' => Money::format($this->amount, $decimals),
            'amount_raw' => $this->amount_raw,
            'block_number' => $this->block_number,
            'confirmations' => (int) $this->confirmations,
            'confirmations_required' => $registry->confirmationsRequired($this->network_code),
            'status' => $this->status->value,
            'explorer_url' => $network?->explorerTxUrl($this->tx_hash),
            'credited_at' => $this->credited_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
