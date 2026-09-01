<?php

namespace App\Http\Resources;

use App\Models\Network;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Network
 */
class NetworkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'chain_id' => $this->chain_id,
            'confirmations_required' => $this->confirmations_required,
            'tokens' => $this->tokenContracts
                ->map(fn ($contract) => [
                    'symbol' => $contract->symbol,
                    'contract_address' => $contract->contract_address,
                    'decimals' => $contract->decimals,
                ])->values(),
        ];
    }
}
