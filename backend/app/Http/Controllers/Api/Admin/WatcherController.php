<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WatcherController extends Controller
{
    /** Proxy to the watcher's own /health endpoint (SPEC §6.4, §6.6). */
    public function health(): JsonResponse
    {
        $url = rtrim((string) config('services.watcher.url'), '/').'/health';

        try {
            $response = Http::timeout((int) config('services.watcher.timeout', 10))->get($url);
        } catch (Throwable $e) {
            Log::warning('Watcher health probe failed', ['error' => $e->getMessage()]);

            // A connection error message carries the internal service URL and
            // resolved address; viewers do not need the internal topology.
            return response()->json([
                'ok' => false,
                'error' => 'watcher_unavailable',
                'message' => app()->environment(['local', 'testing'])
                    ? $e->getMessage()
                    : 'The watcher did not respond.',
            ], 200);
        }

        if (! $response->successful()) {
            return response()->json([
                'ok' => false,
                'error' => 'watcher_unavailable',
                'status' => $response->status(),
            ], 200);
        }

        return response()->json($response->json() ?? ['ok' => false]);
    }
}
