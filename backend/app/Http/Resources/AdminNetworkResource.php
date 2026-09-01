<?php

namespace App\Http\Resources;

use App\Models\Network;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Network
 */
class AdminNetworkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'chain_id' => $this->chain_id,
            'confirmations_required' => $this->confirmations_required,
            'is_enabled' => $this->is_enabled,
            'explorer_tx_url' => $this->explorer_tx_url,
            'explorer_address_url' => $this->explorer_address_url,
            'last_scanned_block' => $this->last_scanned_block,
            'watcher_healthy' => $this->watcher_healthy,
            'watcher_seen_at' => $this->watcher_seen_at?->toIso8601String(),
            'token_contracts' => $this->whenLoaded('tokenContracts', fn () => $this->tokenContracts->map(fn ($c) => [
                'id' => $c->id,
                'symbol' => $c->symbol,
                'contract_address' => $c->contract_address,
                'decimals' => $c->decimals,
                'is_enabled' => $c->is_enabled,
            ])->values()),
        ];
    }
}
