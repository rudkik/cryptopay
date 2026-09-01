<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\TokenResource;
use App\Models\Token;
use Illuminate\Http\Request;

/**
 * @mixin Token
 */
class AdminTokenResource extends TokenResource
{
    use EmbedsMerchant;

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['merchant' => $this->merchantStub()];
    }
}
