<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTokenRequest;
use App\Http\Requests\Admin\UpdateTokenRequest;
use App\Http\Resources\Admin\AdminTokenResource;
use App\Http\Resources\TokenHoldingResource;
use App\Models\Token;
use App\Models\TokenHolding;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TokenController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'merchant_id' => ['nullable', 'uuid'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tokens = Token::query()
            ->with('merchant')
            ->when($filters['merchant_id'] ?? null, fn ($q, $v) => $q->where('merchant_id', $v))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return AdminTokenResource::collection($tokens);
    }

    public function store(StoreTokenRequest $request): JsonResponse
    {
        $token = Token::create(self::withoutNullDefaults($request->validated()));

        // `decimals`, `sold` and `min_purchase` come from DB defaults when the payload
        // omits them; without refresh() they stay null in memory and TokenResource's
        // Money::format() blows up with a TypeError (500) *after* the row is written.
        $token->refresh();

        $this->audit->log('token.created', $token, $request->validated());

        return (new AdminTokenResource($token->load('merchant')))->response()->setStatusCode(201);
    }

    public function show(string $token): AdminTokenResource
    {
        return new AdminTokenResource(Token::query()->with('merchant')->whereKey($token)->firstOrFail());
    }

    public function update(UpdateTokenRequest $request, string $token): AdminTokenResource
    {
        $model = Token::query()->whereKey($token)->firstOrFail();

        $before = $model->getAttributes();
        $model->update(self::withoutNullDefaults($request->validated()));

        $this->audit->log('token.updated', $model, AuditLogger::diff($before, $model));

        return new AdminTokenResource($model->load('merchant'));
    }

    public function destroy(string $token): JsonResponse
    {
        $model = Token::query()->whereKey($token)->firstOrFail();

        // Soft retirement: sold history and holdings must survive.
        $model->forceFill(['is_active' => false])->save();

        $this->audit->log('token.retired', $model);

        return response()->json(['ok' => true]);
    }

    public function holdings(Request $request, string $token): AnonymousResourceCollection
    {
        $holdings = TokenHolding::query()
            ->where('token_id', $token)
            ->with('token')
            ->orderByDesc('amount')
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return TokenHoldingResource::collection($holdings);
    }

    /**
     * `decimals`, `min_purchase` and `is_active` are NOT NULL columns carrying DB
     * defaults, but the admin form posts them as null when the field is left blank.
     * Writing that null violates the constraint (23502 -> 500), so drop the keys and
     * let the column default apply.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withoutNullDefaults(array $data): array
    {
        foreach (['decimals', 'min_purchase', 'is_active'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
