<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\Internal;
use App\Http\Controllers\Api\PublicApi;
use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant API — SPEC §6.1
|--------------------------------------------------------------------------
| Auth: Authorization: Bearer cp_live_<40 hex>. 120 req/min per key.
*/
Route::prefix('v1')
    ->middleware(['api.key', 'throttle:api-v1'])
    ->group(function () {
        Route::get('invoices', [V1\InvoiceController::class, 'index']);
        Route::post('invoices', [V1\InvoiceController::class, 'store'])->middleware('idempotency');
        Route::get('invoices/{invoice}', [V1\InvoiceController::class, 'show']);
        Route::post('invoices/{invoice}/cancel', [V1\InvoiceController::class, 'cancel']);

        Route::get('networks', [V1\NetworkController::class, 'index']);
        Route::get('balances', [V1\BalanceController::class, 'index']);
        Route::get('transactions', [V1\TransactionController::class, 'index']);

        Route::get('tokens', [V1\TokenController::class, 'index']);
        Route::get('tokens/{token}', [V1\TokenController::class, 'show']);

        Route::get('token-purchases', [V1\TokenPurchaseController::class, 'index']);
        Route::post('token-purchases', [V1\TokenPurchaseController::class, 'store'])->middleware('idempotency');
        Route::get('token-purchases/{purchase}', [V1\TokenPurchaseController::class, 'show']);

        Route::get('customers/{customer_id}/holdings', [V1\CustomerHoldingController::class, 'index']);

        Route::get('me', V1\MeController::class);
    });

/*
|--------------------------------------------------------------------------
| Public hosted checkout — SPEC §6.3 (no auth, polled every 5s)
|--------------------------------------------------------------------------
*/
Route::prefix('public')
    ->middleware('throttle:public')
    ->group(function () {
        Route::get('invoices/{invoice}', [PublicApi\InvoiceController::class, 'show']);
    });

/*
|--------------------------------------------------------------------------
| Admin API — SPEC §6.4 (Sanctum personal access tokens)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    Route::post('auth/login', [Admin\AuthController::class, 'login'])->middleware('throttle:admin-login');

    // `active` matters on the read routes too: EnsureAdminRole already
    // rechecks is_active, but without this a deactivated user's existing
    // token keeps full read access to every merchant and invoice.
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('auth/logout', [Admin\AuthController::class, 'logout']);
        Route::get('auth/me', [Admin\AuthController::class, 'me']);

        Route::get('dashboard', Admin\DashboardController::class);

        Route::get('merchants', [Admin\MerchantController::class, 'index']);
        Route::get('merchants/{merchant}', [Admin\MerchantController::class, 'show']);

        Route::get('invoices', [Admin\InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [Admin\InvoiceController::class, 'show']);

        Route::get('transactions', [Admin\TransactionController::class, 'index']);
        Route::get('transactions/{transaction}', [Admin\TransactionController::class, 'show']);

        Route::get('networks', [Admin\NetworkController::class, 'index']);

        Route::get('tokens', [Admin\TokenController::class, 'index']);
        Route::get('tokens/{token}', [Admin\TokenController::class, 'show']);
        Route::get('tokens/{token}/holdings', [Admin\TokenController::class, 'holdings']);
        Route::get('token-purchases', [Admin\TokenPurchaseController::class, 'index']);
        Route::get('token-purchases/{tokenPurchase}', [Admin\TokenPurchaseController::class, 'show']);

        Route::get('webhooks', [Admin\WebhookController::class, 'index']);
        Route::get('webhooks/{webhook}', [Admin\WebhookController::class, 'show']);

        Route::get('balances', [Admin\BalanceController::class, 'index']);
        Route::get('ledger', [Admin\BalanceController::class, 'ledger']);

        Route::get('watcher/health', [Admin\WatcherController::class, 'health']);

        // Mutating endpoints require role=admin; viewers keep read-only access.
        Route::middleware('admin')->group(function () {
            Route::post('merchants', [Admin\MerchantController::class, 'store']);
            Route::put('merchants/{merchant}', [Admin\MerchantController::class, 'update']);
            Route::post('merchants/{merchant}/api-keys', [Admin\MerchantApiKeyController::class, 'store']);
            Route::delete('merchants/{merchant}/api-keys/{keyId}', [Admin\MerchantApiKeyController::class, 'destroy']);
            Route::post('merchants/{merchant}/webhook-secret/rotate', [Admin\MerchantController::class, 'rotateWebhookSecret']);

            Route::post('invoices/{invoice}/cancel', [Admin\InvoiceController::class, 'cancel']);
            Route::post('invoices/{invoice}/simulate-payment', [Admin\InvoiceController::class, 'simulatePayment']);

            Route::put('networks/{code}', [Admin\NetworkController::class, 'update']);
            Route::put('networks/{code}/tokens/{symbol}', [Admin\NetworkController::class, 'updateToken']);

            Route::post('tokens', [Admin\TokenController::class, 'store']);
            Route::put('tokens/{token}', [Admin\TokenController::class, 'update']);
            Route::delete('tokens/{token}', [Admin\TokenController::class, 'destroy']);

            Route::post('webhooks/{webhook}/retry', [Admin\WebhookController::class, 'retry']);

            Route::get('users', [Admin\UserController::class, 'index']);
            Route::post('users', [Admin\UserController::class, 'store']);
            Route::get('users/{user}', [Admin\UserController::class, 'show']);
            Route::put('users/{user}', [Admin\UserController::class, 'update']);
            Route::delete('users/{user}', [Admin\UserController::class, 'destroy']);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Internal API — SPEC §6.5 (watcher <-> backend, X-Internal-Token)
|--------------------------------------------------------------------------
*/
Route::prefix('internal')
    ->middleware('internal')
    ->group(function () {
        Route::get('config', Internal\ConfigController::class);
        Route::get('watch-addresses', Internal\WatchAddressController::class);
        Route::post('transactions', [Internal\TransactionController::class, 'store']);
        Route::post('transactions/batch', [Internal\TransactionController::class, 'batch']);
        Route::post('heartbeat', Internal\HeartbeatController::class);
    });
