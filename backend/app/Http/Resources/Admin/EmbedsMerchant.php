<?php

namespace App\Http\Resources\Admin;

/**
 * The admin UI shows which merchant every row belongs to. Kept out of the
 * merchant-facing resources so the SPEC §6.1 objects stay exactly as specified.
 */
trait EmbedsMerchant
{
    protected function merchantStub(): ?array
    {
        $merchant = $this->resource->relationLoaded('merchant')
            ? $this->resource->merchant
            : $this->resource->merchant()->first();

        return $merchant ? ['id' => $merchant->id, 'name' => $merchant->name] : null;
    }
}
