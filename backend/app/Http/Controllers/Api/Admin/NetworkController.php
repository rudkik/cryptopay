<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateNetworkRequest;
use App\Http\Requests\Admin\UpdateTokenContractRequest;
use App\Http\Resources\AdminNetworkResource;
use App\Models\Network;
use App\Models\TokenContract;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NetworkController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminNetworkResource::collection(
            Network::query()->with('tokenContracts')->orderBy('code')->get()
        );
    }

    public function update(UpdateNetworkRequest $request, string $code): AdminNetworkResource
    {
        $network = Network::query()->where('code', $code)->firstOrFail();

        $network->update($request->validated());

        return new AdminNetworkResource($network->load('tokenContracts'));
    }

    public function updateToken(UpdateTokenContractRequest $request, string $code, string $symbol): AdminNetworkResource
    {
        $contract = TokenContract::query()
            ->where('network_code', $code)
            ->where('symbol', mb_strtoupper($symbol))
            ->firstOrFail();

        $contract->update($request->validated());

        return new AdminNetworkResource(
            Network::query()->where('code', $code)->with('tokenContracts')->firstOrFail()
        );
    }
}
