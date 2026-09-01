<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiKeyResource;
use App\Http\Resources\MerchantResource;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use ResolvesMerchant;

    public function __invoke(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $apiKey = $request->attributes->get('api_key');

        return response()->json([
            'data' => (new MerchantResource($merchant->load('balances')))->toArray($request) + [
                'webhook' => [
                    'url' => $merchant->webhook_url,
                    'configured' => filled($merchant->webhook_url),
                    'events' => [
                        'invoice.confirming', 'invoice.paid', 'invoice.overpaid',
                        'invoice.partially_paid', 'invoice.expired', 'invoice.cancelled',
                        'token_purchase.completed',
                    ],
                    'signature_header' => 'X-CryptoPay-Signature',
                ],
                'api_key' => $apiKey instanceof ApiKey ? (new ApiKeyResource($apiKey))->toArray($request) : null,
            ],
        ]);
    }
}
