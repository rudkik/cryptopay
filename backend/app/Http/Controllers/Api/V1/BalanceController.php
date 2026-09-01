<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Resources\BalanceResource;
use App\Models\Balance;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalanceController extends Controller
{
    use ResolvesMerchant;

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        $balances = Balance::query()
            ->where('merchant_id', $merchant->id)
            ->orderBy('currency')
            ->orderBy('network_code')
            ->get();

        $totals = [];

        foreach ($balances as $balance) {
            $totals[$balance->currency]['available'] = Money::add(
                $totals[$balance->currency]['available'] ?? '0', $balance->available
            );
            $totals[$balance->currency]['pending'] = Money::add(
                $totals[$balance->currency]['pending'] ?? '0', $balance->pending
            );
        }

        // Totals are cross-network, so they use a fixed 6-decimal presentation
        // (USDT/USDC are 6-decimal on every network except BSC).
        $totals = array_map(fn ($t) => [
            'available' => Money::format($t['available'], 6),
            'pending' => Money::format($t['pending'], 6),
        ], $totals);

        return response()->json([
            'data' => BalanceResource::collection($balances)->resolve(),
            'totals' => (object) $totals,
        ]);
    }
}
