<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\HeartbeatRequest;
use App\Models\Network;
use Illuminate\Http\JsonResponse;

class HeartbeatController extends Controller
{
    public function __invoke(HeartbeatRequest $request): JsonResponse
    {
        $data = $request->validated();

        $network = Network::query()->where('code', $data['network'])->firstOrFail();

        $attributes = [
            'watcher_healthy' => (bool) ($data['healthy'] ?? true),
            'watcher_seen_at' => now(),
        ];

        if (array_key_exists('last_scanned_block', $data) && $data['last_scanned_block'] !== null) {
            $attributes['last_scanned_block'] = (int) $data['last_scanned_block'];
        }

        $network->forceFill($attributes)->save();

        return response()->json([
            'ok' => true,
            'network' => $network->code,
            'last_scanned_block' => $network->last_scanned_block,
            'watcher_healthy' => $network->watcher_healthy,
        ]);
    }
}
