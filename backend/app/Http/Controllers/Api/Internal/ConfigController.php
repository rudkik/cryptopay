<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Network;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $networks = Network::query()
            ->with(['tokenContracts' => fn ($q) => $q->where('is_enabled', true)->orderBy('symbol')])
            ->orderBy('code')
            ->get()
            ->map(fn (Network $network) => [
                'code' => $network->code,
                'chain_id' => $network->chain_id,
                'confirmations_required' => $network->confirmations_required,
                'is_enabled' => $network->is_enabled,
                'last_scanned_block' => $network->last_scanned_block,
                'tokens' => $network->tokenContracts->map(fn ($c) => [
                    'symbol' => $c->symbol,
                    'contract_address' => $c->contract_address,
                    'decimals' => $c->decimals,
                ])->values(),
            ])->values();

        return response()->json(['networks' => $networks]);
    }
}
