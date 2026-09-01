<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\StoreTransactionBatchRequest;
use App\Http\Requests\Internal\StoreTransactionRequest;
use App\Services\TransactionIngestService;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionIngestService $ingest) {}

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        return response()->json($this->ingest->ingest($request->validated()));
    }

    public function batch(StoreTransactionBatchRequest $request): JsonResponse
    {
        $results = [];

        foreach ($request->validated()['transactions'] as $payload) {
            $results[] = $this->ingest->ingest($payload);
        }

        return response()->json(['ok' => true, 'results' => $results]);
    }
}
