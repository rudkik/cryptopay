<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\WebhookDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminWebhookDeliveryResource;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class WebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'merchant_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(array_column(WebhookDeliveryStatus::cases(), 'value'))],
            'event' => ['nullable', 'string'],
            'invoice_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $deliveries = WebhookDelivery::query()
            ->with('merchant')
            ->when($filters['merchant_id'] ?? null, fn ($q, $v) => $q->where('merchant_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['invoice_id'] ?? null, fn ($q, $v) => $q->where('invoice_id', $v))
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return AdminWebhookDeliveryResource::collection($deliveries);
    }

    public function show(string $webhook): AdminWebhookDeliveryResource
    {
        return new AdminWebhookDeliveryResource(
            WebhookDelivery::query()->with('merchant')->whereKey($webhook)->firstOrFail()
        );
    }

    public function retry(string $webhook): AdminWebhookDeliveryResource
    {
        $delivery = WebhookDelivery::query()->whereKey($webhook)->firstOrFail();

        return new AdminWebhookDeliveryResource($this->webhooks->retry($delivery)->refresh()->load('merchant'));
    }
}
