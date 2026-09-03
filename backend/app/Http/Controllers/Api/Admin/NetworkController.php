<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateNetworkRequest;
use App\Http\Requests\Admin\UpdateTokenContractRequest;
use App\Http\Resources\AdminNetworkResource;
use App\Models\Network;
use App\Models\TokenContract;
use App\Services\AuditLogger;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NetworkController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return AdminNetworkResource::collection(
            Network::query()->with('tokenContracts')->orderBy('code')->get()
        );
    }

    public function update(UpdateNetworkRequest $request, string $code): AdminNetworkResource
    {
        $network = Network::query()->where('code', $code)->firstOrFail();

        $before = $network->getAttributes();
        $network->update($request->validated());

        $this->audit->log('network.updated', $network, AuditLogger::diff($before, $network));

        return new AdminNetworkResource($network->load('tokenContracts'));
    }

    public function updateToken(UpdateTokenContractRequest $request, string $code, string $symbol): AdminNetworkResource
    {
        $contract = TokenContract::query()
            ->where('network_code', $code)
            ->where('symbol', mb_strtoupper($symbol))
            ->firstOrFail();

        $before = $contract->getAttributes();
        $contract->update($request->validated());

        // The contract address and decimals decide how much every incoming
        // transfer is worth; changing them silently is not an option.
        $this->audit->log('token_contract.updated', $contract, AuditLogger::diff($before, $contract));

        return new AdminNetworkResource(
            Network::query()->where('code', $code)->with('tokenContracts')->firstOrFail()
        );
    }
}
