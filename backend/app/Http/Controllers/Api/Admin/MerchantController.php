<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMerchantRequest;
use App\Http\Requests\Admin\UpdateMerchantRequest;
use App\Http\Resources\MerchantResource;
use App\Models\Merchant;
use App\Services\ApiKeyService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MerchantController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // `q` arrived unvalidated: `?q[]=x` reached Request::string() and threw
        // "Array to string conversion" -> 500 instead of a 422.
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $merchants = Merchant::query()
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$v}%")
                ->orWhere('email', 'like', "%{$v}%")))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->withCount('invoices')
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return MerchantResource::collection($merchants);
    }

    public function store(StoreMerchantRequest $request): JsonResponse
    {
        $merchant = Merchant::create($request->validated() + [
            'webhook_secret' => ApiKeyService::generateWebhookSecret(),
        ]);

        // `is_active` is a DB default; without refresh() it serialises as null and the
        // admin UI renders a brand-new merchant as "Inactive".
        $merchant->refresh();

        $this->audit->log('merchant.created', $merchant, $request->validated());

        return (new MerchantResource($merchant))->response()->setStatusCode(201);
    }

    public function show(string $merchant): MerchantResource
    {
        $model = Merchant::query()
            ->with(['balances', 'apiKeys' => fn ($q) => $q->latest()])
            ->whereKey($merchant)
            ->firstOrFail();

        return new MerchantResource($model);
    }

    public function update(UpdateMerchantRequest $request, string $merchant): MerchantResource
    {
        $model = Merchant::query()->whereKey($merchant)->firstOrFail();

        $before = $model->getAttributes();
        $model->update($request->validated());

        $this->audit->log('merchant.updated', $model, AuditLogger::diff($before, $model));

        return new MerchantResource($model->load(['balances', 'apiKeys']));
    }

    public function rotateWebhookSecret(string $merchant): JsonResponse
    {
        $model = Merchant::query()->whereKey($merchant)->firstOrFail();

        $secret = ApiKeyService::generateWebhookSecret();
        $model->forceFill(['webhook_secret' => $secret])->save();

        // The value itself is never written to the trail.
        $this->audit->log('merchant.webhook_secret_rotated', $model);

        // Shown once so it can be copied into the merchant's integration.
        return response()->json([
            'webhook_secret' => $secret,
            // resolve() filters the unloaded balances/api_keys relations out;
            // toArray() leaked both as `{}` (see MeController).
            'merchant' => (new MerchantResource($model))->resolve(request()),
        ]);
    }
}
