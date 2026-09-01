<?php

namespace App\Providers;

use App\Exceptions\ErrorResponse;
use App\Models\ApiKey;
use App\Support\NetworkRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NetworkRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRouteBindings();
        $this->configureRateLimiting();
    }

    /**
     * Every uuid-keyed route parameter is constrained, so a malformed id is a
     * clean 404 instead of a Postgres "invalid input syntax for type uuid".
     */
    private function configureRouteBindings(): void
    {
        foreach (['invoice', 'merchant', 'token', 'purchase', 'tokenPurchase', 'transaction', 'webhook', 'keyId'] as $parameter) {
            Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }

        Route::pattern('user', '[0-9]+');
    }

    /**
     * SPEC §6.1: 120 req/min per API key on the merchant API. Limits return the
     * standard error envelope rather than Laravel's default body.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api-v1', function (Request $request) {
            $apiKey = $request->attributes->get('api_key');

            // The resolved key is preferred, but the bearer token's hash is a
            // safe fallback: it still buckets per credential even if this
            // limiter ever runs before authentication.
            $key = match (true) {
                $apiKey instanceof ApiKey => 'key:'.$apiKey->id,
                filled($request->bearerToken()) => 'token:'.hash('sha256', (string) $request->bearerToken()),
                default => 'ip:'.$request->ip(),
            };

            return Limit::perMinute(120)
                ->by($key)
                ->response(fn () => ErrorResponse::make('rate_limited', 'Too many requests. The limit is 120 requests per minute.', 429));
        });

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(120)
            ->by($request->ip())
            ->response(fn () => ErrorResponse::make('rate_limited', 'Too many requests.', 429)));

        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(20)
            ->by($request->ip())
            ->response(fn () => ErrorResponse::make('rate_limited', 'Too many login attempts.', 429)));
    }
}
