<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NetworkResource;
use App\Models\Network;
use App\Services\WalletService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NetworkController extends Controller
{
    public function __construct(private readonly WalletService $wallets) {}

    public function index(): AnonymousResourceCollection
    {
        $networks = Network::query()
            ->where('is_enabled', true)
            ->with(['tokenContracts' => fn ($q) => $q->where('is_enabled', true)->orderBy('symbol')])
            ->orderBy('name')
            ->get()
            // A network with no deposit wallet (SPEC §3) cannot hand out an
            // address, so it is not something a merchant may create an invoice
            // on — advertising it here would only produce a 422 later.
            ->filter(fn (Network $network) => $this->wallets->isConfigured($network->code))
            ->values();

        return NetworkResource::collection($networks);
    }
}
