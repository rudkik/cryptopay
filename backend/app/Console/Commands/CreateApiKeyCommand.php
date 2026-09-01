<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Services\ApiKeyService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateApiKeyCommand extends Command
{
    protected $signature = 'cryptopay:create-api-key {merchant : Merchant id or name} {--name=CLI key : Label for the key}';

    protected $description = 'Issue a new merchant API key and print the plaintext once';

    public function handle(ApiKeyService $apiKeys): int
    {
        $needle = (string) $this->argument('merchant');

        // Postgres rejects a non-UUID literal against a uuid column outright,
        // so the id lookup only happens when the argument actually is one.
        $merchant = Str::isUuid($needle)
            ? Merchant::query()->whereKey($needle)->first()
            : null;

        $merchant ??= Merchant::query()->where('name', $needle)->first();

        if (! $merchant) {
            $this->error("No merchant matches [{$needle}].");

            return self::FAILURE;
        }

        [$apiKey, $plaintext] = $apiKeys->generate($merchant, (string) $this->option('name'));

        $this->info("API key created for merchant [{$merchant->name}].");
        $this->line('');
        $this->line("  {$plaintext}");
        $this->line('');
        $this->warn('This is the only time the key is shown. Store it now.');
        $this->line("Prefix: {$apiKey->key_prefix}  Id: {$apiKey->id}");

        return self::SUCCESS;
    }
}
