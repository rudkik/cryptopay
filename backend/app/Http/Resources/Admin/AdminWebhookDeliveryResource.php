<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\WebhookDeliveryResource;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;

/**
 * @mixin WebhookDelivery
 */
class AdminWebhookDeliveryResource extends WebhookDeliveryResource
{
    use EmbedsMerchant;

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['merchant' => $this->merchantStub()];
    }
}
