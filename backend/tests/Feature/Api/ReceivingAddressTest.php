<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Exceptions\WalletNotConfiguredException;
use App\Models\AuditLog;
use App\Models\DepositAddress;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AddressService;
use App\Services\InvoiceService;
use App\Support\NetworkRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The static receiving-address list (Admin → Addresses) and how invoices lease
 * from it before falling back to HD derivation.
 */
class ReceivingAddressTest extends TestCase
{
    use RefreshDatabase;

    private const TRON_A = 'TN3W4H6rK2ce4vX9YnFQHwKENnHjoxb3m9';

    private const TRON_B = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private const EVM_A = '0xdAC17F958D2ee523a2206206994597C13D831ec7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        // No xpub anywhere: the list is the only source of addresses.
        $this->fakeWatcher(evmDerivation: false, tronDerivation: false);
    }

    private function admin(): string
    {
        return $this->adminToken(User::factory()->create(['role' => UserRole::Admin->value]));
    }

    /** @return array<string, mixed> */
    private function addAddress(array $overrides = []): array
    {
        return $this->withToken($this->admin())
            ->postJson('/api/admin/receiving-addresses', $overrides + [
                'network' => 'tron',
                'address' => self::TRON_A,
                'currencies' => ['USDT'],
                'label' => 'Main',
            ])
            ->assertCreated()
            ->json('data');
    }

    public function test_an_admin_lists_an_address_with_its_network_and_currencies(): void
    {
        $item = $this->addAddress();

        $this->assertSame('tron', $item['network']);
        $this->assertSame('TRC-20', $item['standard']);
        $this->assertSame(['USDT'], $item['currencies']);
        $this->assertSame('free', $item['status']);
        $this->assertSame(100, $item['priority']);
        $this->assertStringContainsString(self::TRON_A, (string) $item['explorer_url']);

        $log = AuditLog::where('action', 'receiving_address.created')->firstOrFail();
        $this->assertSame(self::TRON_A, $log->changes['address']);

        $this->withToken($this->admin())->getJson('/api/admin/receiving-addresses?network=tron')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.address', self::TRON_A);
    }

    public function test_the_address_format_is_checked_against_the_network(): void
    {
        $this->assertInvalidField(
            $this->withToken($this->admin())->postJson('/api/admin/receiving-addresses', [
                'network' => 'tron', 'address' => self::EVM_A,
            ]),
            'address',
        );

        $this->assertInvalidField(
            $this->withToken($this->admin())->postJson('/api/admin/receiving-addresses', [
                'network' => 'ethereum', 'address' => self::TRON_A,
            ]),
            'address',
        );

        // Only currencies that actually exist on the network.
        $this->assertInvalidField(
            $this->withToken($this->admin())->postJson('/api/admin/receiving-addresses', [
                'network' => 'tron', 'address' => self::TRON_A, 'currencies' => ['DAI'],
            ]),
            'currencies.0',
        );
    }

    public function test_the_same_evm_address_cannot_be_listed_twice_regardless_of_case(): void
    {
        $this->addAddress(['network' => 'bsc', 'address' => self::EVM_A, 'currencies' => []]);

        $this->assertInvalidField(
            $this->withToken($this->admin())->postJson('/api/admin/receiving-addresses', [
                'network' => 'bsc', 'address' => strtolower(self::EVM_A),
            ]),
            'address',
        );

        // The same string on another network is a different address.
        $this->addAddress(['network' => 'ethereum', 'address' => self::EVM_A, 'currencies' => []]);
    }

    public function test_a_listed_address_makes_the_pair_available_on_the_checkout(): void
    {
        [$merchant, $key] = $this->makeMerchant();

        // Nothing configured yet: no options at all.
        $none = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10'])
            ->assertCreated();
        $this->assertSame([], $this->getJson('/api/public/invoices/'.$none->json('data.id'))->json('data.options'));

        $this->addAddress(); // tron / USDT only
        $this->forgetWalletCache();
        // The registry memoises per application instance, which a feature
        // test shares across requests; a real deployment boots one per request.
        app()->forgetInstance(NetworkRegistry::class);

        $options = $this->getJson('/api/public/invoices/'.$none->json('data.id'))->json('data.options');

        $this->assertSame([['tron', 'USDT']], array_map(fn ($o) => [$o['network'], $o['currency']], $options));
    }

    public function test_an_invoice_leases_the_listed_address_and_a_second_one_waits(): void
    {
        $this->addAddress();
        $this->forgetWalletCache();
        [$merchant, $key] = $this->makeMerchant();

        $first = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron', 'expires_in' => 600])
            ->assertCreated();

        $this->assertSame(self::TRON_A, $first->json('data.address'));

        $lease = DepositAddress::where('address', self::TRON_A)->firstOrFail();
        $this->assertNull($lease->derivation_index);
        $this->assertSame($first->json('data.id'), $lease->invoice_id);
        $this->assertTrue($lease->isLeased());
        // 600s of invoice + 1800s of grace.
        $this->assertEqualsWithDelta(2400, now()->diffInSeconds($lease->leased_until), 5);

        $listed = $this->withToken($this->admin())->getJson('/api/admin/receiving-addresses')->json('data.0');
        $this->assertSame('busy', $listed['status']);
        $this->assertSame($first->json('data.id'), $listed['lease']['invoice']['id']);

        // The only address is busy and there is no xpub: refuse rather than share.
        $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'no_free_address');
    }

    public function test_addresses_rotate_by_priority_then_least_recently_used(): void
    {
        $this->addAddress(['address' => self::TRON_A, 'priority' => 10]);
        $this->addAddress(['address' => self::TRON_B, 'priority' => 20]);
        $this->forgetWalletCache();
        [$merchant] = $this->makeMerchant();

        $service = app(AddressService::class);

        $this->assertSame(self::TRON_A, $service->allocate('tron', $merchant, 'USDT', 60)->address);
        $this->assertSame(self::TRON_B, $service->allocate('tron', $merchant, 'USDT', 60)->address);

        // Both leases run out: the higher-priority one comes back first.
        DepositAddress::query()->update(['leased_until' => now()->subMinute()]);
        $this->assertSame(self::TRON_A, $service->allocate('tron', $merchant, 'USDT', 60)->address);
    }

    public function test_cancelling_an_invoice_frees_its_address(): void
    {
        $this->addAddress();
        $this->forgetWalletCache();
        [$merchant, $key] = $this->makeMerchant();

        $id = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()
            ->json('data.id');

        app(InvoiceService::class)->cancel(Invoice::findOrFail($id));

        $this->assertFalse(DepositAddress::where('address', self::TRON_A)->firstOrFail()->isLeased());

        $next = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated();

        $this->assertSame(self::TRON_A, $next->json('data.address'));
        $this->assertSame($next->json('data.id'), DepositAddress::where('address', self::TRON_A)->value('invoice_id'));
    }

    public function test_a_payment_is_credited_to_the_invoice_holding_the_lease(): void
    {
        $this->addAddress();
        $this->forgetWalletCache();
        [$merchant, $key] = $this->makeMerchant();

        $id = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->internalHeaders())
            ->postJson('/api/internal/transactions', [
                'network' => 'tron',
                'tx_hash' => $this->txHash('lease-1'),
                'log_index' => 0,
                'from_address' => self::TRON_B,
                'to_address' => self::TRON_A,
                'symbol' => 'USDT',
                'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'amount' => '10',
                'amount_raw' => '10000000',
                'block_number' => 100,
                'confirmations' => 30,
                'status' => 'confirmed',
            ])
            ->assertOk()
            ->assertJsonPath('invoice_id', $id);

        $this->assertSame('paid', Invoice::findOrFail($id)->status->value);
    }

    public function test_the_watcher_sees_listed_addresses_before_any_lease(): void
    {
        $this->addAddress();

        $this->withHeaders($this->internalHeaders())
            ->getJson('/api/internal/watch-addresses?network=tron')
            ->assertOk()
            ->assertJsonPath('addresses.0.address', self::TRON_A);
    }

    public function test_the_xpub_is_the_overflow_when_every_listed_address_is_busy(): void
    {
        $this->fakeWatcher(evmDerivation: false, tronDerivation: true);
        $this->addAddress();
        $this->forgetWalletCache();
        [$merchant] = $this->makeMerchant();

        $service = app(AddressService::class);

        $this->assertSame(self::TRON_A, $service->allocate('tron', $merchant, 'USDT', 60)->address);

        $derived = $service->allocate('tron', $merchant, 'USDT', 60);
        $this->assertNotSame(self::TRON_A, $derived->address);
        $this->assertSame(0, $derived->derivation_index);
    }

    public function test_a_currency_the_address_does_not_accept_is_not_served_by_it(): void
    {
        $this->addAddress(['network' => 'ethereum', 'address' => self::EVM_A, 'currencies' => ['USDC']]);
        $this->forgetWalletCache();
        [$merchant] = $this->makeMerchant();

        $this->expectException(WalletNotConfiguredException::class);
        app(AddressService::class)->allocate('ethereum', $merchant, 'USDT', 60);
    }

    public function test_a_busy_address_cannot_be_deleted_but_can_be_disabled(): void
    {
        $item = $this->addAddress();
        $this->forgetWalletCache();
        [$merchant] = $this->makeMerchant();
        app(AddressService::class)->allocate('tron', $merchant, 'USDT', 600);

        $this->withToken($this->admin())
            ->deleteJson('/api/admin/receiving-addresses/'.$item['id'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');

        $this->withToken($this->admin())
            ->putJson('/api/admin/receiving-addresses/'.$item['id'], ['is_enabled' => false, 'label' => 'Paused'])
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.label', 'Paused');

        DepositAddress::query()->update(['leased_until' => now()->subMinute()]);

        $this->withToken($this->admin())
            ->deleteJson('/api/admin/receiving-addresses/'.$item['id'])
            ->assertOk();

        $this->assertDatabaseMissing('receiving_addresses', ['id' => $item['id']]);
        // History survives: the lease row is kept and still watched.
        $this->assertDatabaseHas('deposit_addresses', ['address' => self::TRON_A, 'receiving_address_id' => null]);
    }

    public function test_a_viewer_reads_the_list_but_cannot_change_it(): void
    {
        $viewer = $this->adminToken(User::factory()->create(['role' => UserRole::Viewer->value]));

        $this->withToken($viewer)->getJson('/api/admin/receiving-addresses')->assertOk();
        $this->withToken($viewer)
            ->postJson('/api/admin/receiving-addresses', ['network' => 'tron', 'address' => self::TRON_A])
            ->assertForbidden();
    }
}
