<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Enums\NetworkCode;
use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\WalletService;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;

/**
 * What a client needs before it holds any credential: which optional modules
 * this deployment runs (config/features.php) and which chains it accepts.
 *
 * Unauthenticated by design and mounted inside the `public` prefix (SPEC §6.3),
 * so it shares that group's 120 req/min per-IP limiter. It exposes nothing an
 * operator has not already published: the enabled network codes are the same
 * list the hosted checkout shows every payer.
 */
class ConfigController extends Controller
{
    public function __construct(private readonly WalletService $wallets) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'features' => FeatureFlags::all(),
            'networks' => $this->enabledNetworkCodes(),
        ]);
    }

    /**
     * Enabled networks in the order SPEC §2 lists them (the order the admin
     * wallets page and the checkout options use); an unknown code sorts last
     * rather than blowing up, since networks are rows an operator may add.
     *
     * Filtered by `isConfigured()` for the same reason `GET /api/v1/networks`
     * and the checkout's `options` are (SPEC §3): a network with no deposit
     * wallet cannot hand out an address, so advertising it here only produced a
     * dead end — and the two lists disagreeing (three codes here, `[]` there)
     * is worse than either answer on its own.
     *
     * @return list<string>
     */
    private function enabledNetworkCodes(): array
    {
        $order = array_flip(array_column(NetworkCode::cases(), 'value'));

        return Network::query()
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (Network $network) => $this->wallets->isConfigured($network->code))
            ->sortBy(fn (Network $network) => $order[$network->code] ?? PHP_INT_MAX)
            ->pluck('code')
            ->values()
            ->all();
    }
}
