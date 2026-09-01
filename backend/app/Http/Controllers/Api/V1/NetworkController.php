<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NetworkResource;
use App\Models\Network;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NetworkController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $networks = Network::query()
            ->where('is_enabled', true)
            ->with(['tokenContracts' => fn ($q) => $q->where('is_enabled', true)->orderBy('symbol')])
            ->orderBy('name')
            ->get();

        return NetworkResource::collection($networks);
    }
}
