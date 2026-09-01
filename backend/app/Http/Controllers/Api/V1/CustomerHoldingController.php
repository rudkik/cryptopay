<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Resources\TokenHoldingResource;
use App\Models\TokenHolding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerHoldingController extends Controller
{
    use ResolvesMerchant;

    public function index(Request $request, string $customerId): AnonymousResourceCollection
    {
        $merchant = $this->merchant($request);

        $holdings = TokenHolding::query()
            ->where('merchant_id', $merchant->id)
            ->where('customer_id', $customerId)
            ->with('token')
            ->get();

        return TokenHoldingResource::collection($holdings);
    }
}
