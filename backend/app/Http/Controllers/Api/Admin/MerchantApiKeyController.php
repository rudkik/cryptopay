<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\Merchant;
use App\Services\ApiKeyService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

class MerchantApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $apiKeys,
        private readonly AuditLogger $audit,
    ) {}

    public function store(StoreApiKeyRequest $request, string $merchant): JsonResponse
    {
        $model = Merchant::query()->whereKey($merchant)->firstOrFail();

        [$apiKey, $plaintext] = $this->apiKeys->generate($model, $request->validated()['name']);

        $this->audit->log('api_key.created', $apiKey, [
            'merchant_id' => $model->id,
            'name' => $apiKey->name,
            'key_prefix' => $apiKey->key_prefix,
        ]);

        // The plaintext is returned exactly once and never stored.
        return response()->json([
            'key' => $plaintext,
            'api_key' => (new ApiKeyResource($apiKey))->toArray($request),
        ], 201);
    }

    public function destroy(string $merchant, string $keyId): JsonResponse
    {
        $apiKey = ApiKey::query()
            ->where('merchant_id', $merchant)
            ->whereKey($keyId)
            ->firstOrFail();

        $this->apiKeys->revoke($apiKey);

        $this->audit->log('api_key.revoked', $apiKey, [
            'merchant_id' => $apiKey->merchant_id,
            'key_prefix' => $apiKey->key_prefix,
        ]);

        return response()->json([
            'ok' => true,
            'api_key' => (new ApiKeyResource($apiKey))->toArray(request()),
        ]);
    }
}
