<?php

namespace App\Http\Controllers\Api\Internal;

use App\Enums\NetworkCode;
use App\Http\Controllers\Controller;
use App\Models\DepositAddress;
use App\Models\ReceivingAddress;
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
            ->map(fn ($a) => ['id' => $a->id, 'address' => $a->address]);

        // Static receiving addresses are watched from the moment they are
        // listed, before any invoice has leased them, so nothing sent to them
        // early is missed. Once leased they also exist as deposit_addresses
        // rows; the watcher keeps a set, so the duplicate is harmless.
        $listed = $addresses->pluck('address')->map(fn (string $a) => mb_strtolower($a))->flip();

        $pool = ReceivingAddress::query()
            ->where('network_code', $filters['network'])
            ->where('is_enabled', true)
            ->when($filters['updated_since'] ?? null, fn ($q, $v) => $q->where('updated_at', '>=', $v))
            ->orderBy('priority')
            ->get(['id', 'address'])
            ->reject(fn (ReceivingAddress $a) => $listed->has(mb_strtolower($a->address)))
            ->map(fn (ReceivingAddress $a) => ['id' => $a->id, 'address' => $a->address]);

        return response()->json(['addresses' => $addresses->concat($pool)->values()]);
    }
}
