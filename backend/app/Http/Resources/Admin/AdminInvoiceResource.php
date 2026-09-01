<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\InvoiceResource;
use App\Http\Resources\WebhookDeliveryResource;
use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * The SPEC §6.1 Invoice plus the admin-only extras: the owning merchant and,
 * on the detail view, its webhook deliveries.
 *
 * @mixin Invoice
 */
class AdminInvoiceResource extends InvoiceResource
{
    use EmbedsMerchant;

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'merchant' => $this->merchantStub(),
            'webhooks' => $this->whenLoaded(
                'webhookDeliveries',
                fn () => WebhookDeliveryResource::collection($this->webhookDeliveries)->resolve(),
            ),
        ];
    }
}
