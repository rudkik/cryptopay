<?php

namespace App\Http\Controllers\Api\Internal;

use App\Enums\NetworkCode;
use App\Http\Controllers\Controller;
use App\Models\DepositAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WatchAddressController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'network' => ['required', Rule::in(array_column(NetworkCode::cases(), 'value'))],
            'updated_since' => ['nullable', 'date'],
        ]);

        $addresses = DepositAddress::query()
            ->where('network_code', $filters['network'])
            ->where('is_active', true)
            ->when($filters['updated_since'] ?? null, fn ($q, $v) => $q->where('updated_at', '>=', $v))
            ->orderBy('derivation_index')
            ->get(['id', 'address'])
            ->map(fn ($a) => ['id' => $a->id, 'address' => $a->address])
            ->values();

        return response()->json(['addresses' => $addresses]);
    }
}
