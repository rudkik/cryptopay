<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Laravel;

use CryptoPay\Sdk\Client;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for the CryptoPay SDK.
 *
 * This class extends Illuminate\Support\ServiceProvider and is therefore
 * only safe to reference from an application that has illuminate/support
 * installed. The package itself has zero framework dependencies: Composer
 * only autoloads this class when something (typically Laravel's package
 * auto-discovery, via the `extra.laravel.providers` entry in composer.json)
 * actually references it, so its mere presence in src/Laravel does not
 * pull illuminate/support into a non-Laravel consumer.
 */
final class CryptoPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/cryptopay.php', 'cryptopay');

        $this->app->singleton('cryptopay', function (): Client {
            /** @var array<string, mixed> $config */
            $config = $this->app['config']['cryptopay'] ?? [];

            return new Client(
                (string) ($config['api_key'] ?? ''),
                (string) ($config['base_url'] ?? ''),
                ['timeout' => (int) ($config['timeout'] ?? 30)]
            );
        });

        $this->app->alias('cryptopay', Client::class);
    }

    public function boot(): void
    {
        if (function_exists('config_path')) {
            $this->publishes([
                __DIR__.'/../../config/cryptopay.php' => config_path('cryptopay.php'),
            ], 'cryptopay-config');
        }

        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('cryptopay.webhook', VerifyCryptoPayWebhook::class);
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['cryptopay', Client::class];
    }
}
