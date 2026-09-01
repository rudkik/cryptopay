<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Resources\TokenResource;
use App\Models\Token;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TokenController extends Controller
{
    use ResolvesMerchant;

    public function index(Request $request): AnonymousResourceCollection
    {
        $merchant = $this->merchant($request);

        $tokens = Token::query()
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return TokenResource::collection($tokens);
    }

    public function show(Request $request, string $token): TokenResource
    {
        $merchant = $this->merchant($request);

        $model = Token::query()
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->whereKey($token)
            ->firstOrFail();

        return new TokenResource($model);
    }
}
