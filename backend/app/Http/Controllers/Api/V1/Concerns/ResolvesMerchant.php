<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Merchant;
use Illuminate\Http\Request;

trait ResolvesMerchant
{
    protected function merchant(Request $request): Merchant
    {
        $merchant = $request->attributes->get('merchant');

        abort_unless($merchant instanceof Merchant, 401);

        return $merchant;
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return min(100, max(1, (int) $request->integer('per_page', $default)));
    }
}
