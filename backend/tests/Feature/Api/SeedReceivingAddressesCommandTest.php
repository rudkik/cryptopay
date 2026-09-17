<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\DepositAddress;
use App\Models\Merchant;
use App\Models\ReceivingAddress;
use App\Models\Wallet;
use App\Services\AddressService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `php artisan cryptopay:seed-addresses` — the first N HD addresses into the static list. */
class SeedReceivingAddressesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const XPUB = 'xpub6BxCZ3ymTqx7FTvbjbXNVuG7uhUtNhX4ARa3hxgMy3uRoGprjvnrTJXRySx2s1tnXQAtBgQFEk1r7iPGXtBTFxd6cdeqUUWePXsFvSuwLEK';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
    }

    public function test_it_lists_the_first_n_addresses_of_every_network_with_a_db_xpub_and_raises_next_index(): void
    {
        $this->fakeWatcher(evmDerivation: false, tronDerivation: false);
        app(WalletService::class)->store(['ethereum', 'bsc', 'tron'], self::XPUB, null, null);

        $this->artisan('cryptopay:seed-addresses', ['--count' => 3])
            ->expectsOutputToContain('3 added, 0 skipped')
            ->assertSuccessful();

        foreach (['ethereum', 'bsc', 'tron'] as $network) {
            $rows = ReceivingAddress::query()->where('network_code', $network)->orderBy('priority')->get();

            $this->assertCount(3, $rows, $network);
            $this->assertSame(
                [0, 1, 2],
                $rows->pluck('priority')->all(),
            );
            $this->assertSame(['HD #0', 'HD #1', 'HD #2'], $rows->pluck('label')->all());
            $this->assertSame($this->fakeAddress($network, 1, self::XPUB), $rows[1]->address);
            $this->assertSame([], $rows[1]->currencyList(), 'every currency of the network');
            $this->assertTrue($rows[1]->is_enabled);

            $this->assertSame(3, (int) Wallet::query()->where('network_code', $network)->value('next_index'));
        }

        $this->assertSame(9, AuditLog::query()->where('action', 'receiving_address.created')->count());
    }

    public function test_it_uses_the_watcher_env_key_when_no_xpub_is_stored(): void
    {
        $this->fakeWatcher(evmDerivation: false, tronDerivation: true);

        $this->artisan('cryptopay:seed-addresses', ['--count' => 2, '--network' => ['tron']])
            ->assertSuccessful();

        $this->assertSame(
            [$this->fakeAddress('tron', 0), $this->fakeAddress('tron', 1)],
            ReceivingAddress::query()->where('network_code', 'tron')->orderBy('priority')->pluck('address')->all(),
        );
        $this->assertSame(0, ReceivingAddress::query()->where('network_code', 'ethereum')->count());
    }

    public function test_it_is_idempotent_and_skips_indexes_already_issued_to_invoices(): void
    {
        $this->fakeWatcher(evmDerivation: false, tronDerivation: false);
        app(WalletService::class)->store(['tron'], self::XPUB, null, null);

        // Index 0 was already derived for an invoice before the list existed.
        DepositAddress::create([
            'network_code' => 'tron',
            'address' => $this->fakeAddress('tron', 0, self::XPUB),
            'derivation_index' => 0,
            'merchant_id' => Merchant::factory()->create()->id,
            'is_active' => true,
        ]);
        Wallet::query()->where('network_code', 'tron')->update(['next_index' => 1]);

        $this->artisan('cryptopay:seed-addresses', ['--count' => 3, '--network' => ['tron']])
            ->expectsOutputToContain('already issued to an invoice')
            ->expectsOutputToContain('2 added, 1 skipped')
            ->assertSuccessful();

        $this->artisan('cryptopay:seed-addresses', ['--count' => 3, '--network' => ['tron']])
            ->expectsOutputToContain('0 added, 3 skipped')
            ->assertSuccessful();

        $this->assertSame(2, ReceivingAddress::query()->where('network_code', 'tron')->count());
        $this->assertSame(3, (int) Wallet::query()->where('network_code', 'tron')->value('next_index'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->fakeWatcher(evmDerivation: false, tronDerivation: false);
        app(WalletService::class)->store(['tron'], self::XPUB, null, null);

        $this->artisan('cryptopay:seed-addresses', ['--count' => 2, '--network' => ['tron'], '--dry-run' => true])
            ->expectsOutputToContain('would add')
            ->assertSuccessful();

        $this->assertSame(0, ReceivingAddress::query()->count());
        $this->assertSame(0, (int) Wallet::query()->where('network_code', 'tron')->value('next_index'));
    }

    public function test_a_network_without_any_xpub_is_reported_and_fails_the_run(): void
    {
        $this->fakeWatcher(evmDerivation: false, tronDerivation: false);

        $this->artisan('cryptopay:seed-addresses', ['--count' => 2, '--network' => ['bsc']])
            ->expectsOutputToContain('no xpub')
            ->assertFailed();

        $this->assertSame(0, ReceivingAddress::query()->count());
    }

    public function test_pooled_addresses_are_then_leased_before_new_hd_ones_are_derived(): void
    {
        $this->fakeWatcher(evmDerivation: false, tronDerivation: false);
        app(WalletService::class)->store(['tron'], self::XPUB, null, null);

        $this->artisan('cryptopay:seed-addresses', ['--count' => 2, '--network' => ['tron']])->assertSuccessful();

        $merchant = Merchant::factory()->create();
        $addresses = app(AddressService::class);

        $first = $addresses->allocate('tron', $merchant, 'USDT');
        $second = $addresses->allocate('tron', $merchant, 'USDT');
        $third = $addresses->allocate('tron', $merchant, 'USDT');

        $this->assertNotNull($first->receiving_address_id);
        $this->assertNotNull($second->receiving_address_id);
        $this->assertNotSame($first->address, $second->address);

        // Both pooled addresses are busy: the overflow derives index 2, not 0 again.
        $this->assertNull($third->receiving_address_id);
        $this->assertSame(2, $third->derivation_index);
        $this->assertSame($this->fakeAddress('tron', 2, self::XPUB), $third->address);
    }
}
