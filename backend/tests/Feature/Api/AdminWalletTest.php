<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Exceptions\WalletNotConfiguredException;
use App\Models\AuditLog;
use App\Models\DepositAddress;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AddressService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin Wallet page (SPEC §3, §6.4): which xpub deposit addresses are
 * derived from, who set it, and what has landed on the addresses it issued.
 */
class AdminWalletTest extends TestCase
{
    use RefreshDatabase;

    /** A syntactically plausible account-level key; the watcher is faked. */
    private const XPUB = 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz';

    private const OTHER_XPUB = 'xpub6BosfCnifzxcFwrSzQiqu2DBVTshkCXacvNsWGYJVVhhawA7d4R5WSWGFNbi8Aw6ZRc1brxMyWMzG3DSSSSoekkudhUd9yLb6qx39T9nMdj';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();
    }

    private function admin(): string
    {
        return $this->adminToken(User::factory()->create(['role' => UserRole::Admin->value]));
    }

    public function test_every_network_reports_the_watcher_env_key_as_its_source(): void
    {
        $response = $this->withToken($this->admin())->getJson('/api/admin/wallets')->assertOk();

        $this->assertCount(3, $response->json('data'));

        $response
            ->assertJsonPath('data.0.network', 'ethereum')
            ->assertJsonPath('data.0.standard', 'ERC-20')
            ->assertJsonPath('data.0.source', 'env')
            ->assertJsonPath('data.0.configured', true)
            ->assertJsonPath('data.0.xpub_masked', null)
            ->assertJsonPath('data.0.derivation_path', "m/44'/60'/0'/0")
            ->assertJsonPath('data.2.network', 'tron')
            ->assertJsonPath('data.2.derivation_path', "m/44'/195'/0'/0")
            ->assertJsonPath('data.2.source', 'env')
            ->assertJsonPath('data.0.received.USDT', '0.00');
    }

    public function test_a_network_with_no_key_anywhere_reports_none(): void
    {
        $this->fakeWatcher(evmDerivation: false, tronDerivation: false);

        $this->withToken($this->admin())->getJson('/api/admin/wallets')
            ->assertOk()
            ->assertJsonPath('data.0.source', 'none')
            ->assertJsonPath('data.0.configured', false)
            ->assertJsonPath('data.2.source', 'none');
    }

    public function test_a_stored_key_overrides_the_env_fallback_and_is_only_ever_masked(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value, 'name' => 'Ada']);

        $response = $this->withToken($this->adminToken($admin))
            ->putJson('/api/admin/wallets/tron', ['xpub' => self::XPUB, 'label' => 'Ledger Nano'])
            ->assertOk()
            ->assertJsonPath('data.source', 'database')
            ->assertJsonPath('data.label', 'Ledger Nano')
            ->assertJsonPath('data.xpub_set_by.name', 'Ada')
            ->assertJsonPath('data.xpub_masked', 'xpub6CUGRU…3fDVmz');

        // Nothing anywhere in the payload carries the key itself.
        $this->assertStringNotContainsString(self::XPUB, $response->getContent());
        $this->assertSame(self::XPUB, Wallet::where('network_code', 'tron')->value('xpub'));

        // A fresh read agrees, and still never returns the full value.
        $index = $this->withToken($this->admin())->getJson('/api/admin/wallets')->assertOk();
        $this->assertStringNotContainsString(self::XPUB, $index->getContent());
        $index->assertJsonPath('data.2.source', 'database');
    }

    public function test_saving_an_evm_key_can_cover_both_evm_chains_and_is_audited_masked(): void
    {
        $this->withToken($this->admin())
            ->putJson('/api/admin/wallets/ethereum', [
                'xpub' => self::XPUB,
                'label' => 'Treasury',
                'apply_to_evm' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.source', 'database');

        $this->assertSame(self::XPUB, Wallet::where('network_code', 'ethereum')->value('xpub'));
        $this->assertSame(self::XPUB, Wallet::where('network_code', 'bsc')->value('xpub'));
        // Tron has its own coin type and is never touched by an EVM save.
        $this->assertNull(Wallet::where('network_code', 'tron')->value('xpub'));

        $log = AuditLog::where('action', 'wallet.xpub_updated')->firstOrFail();

        $this->assertSame(['ethereum', 'bsc'], $log->changes['networks']);
        $this->assertSame('xpub6CUGRU…3fDVmz', $log->changes['xpub_masked']);
        $this->assertStringNotContainsString(self::XPUB, json_encode($log->changes));
    }

    public function test_apply_to_evm_is_ignored_for_tron(): void
    {
        $this->withToken($this->admin())
            ->putJson('/api/admin/wallets/tron', ['xpub' => self::XPUB, 'apply_to_evm' => true])
            ->assertOk();

        $this->assertNull(Wallet::where('network_code', 'ethereum')->value('xpub'));
        $this->assertSame(self::XPUB, Wallet::where('network_code', 'tron')->value('xpub'));
    }

    public function test_preview_derives_without_saving_anything(): void
    {
        $this->withToken($this->admin())
            ->postJson('/api/admin/wallets/tron/preview', ['xpub' => self::XPUB])
            ->assertOk()
            ->assertJsonCount(5, 'addresses')
            ->assertJsonPath('addresses.0.index', 0)
            ->assertJsonPath('addresses.0.path', "m/44'/195'/0'/0/0")
            ->assertJsonPath('addresses.4.index', 4);

        $this->assertNull(Wallet::where('network_code', 'tron')->value('xpub'));

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/addresses/derive-batch')
            && $request['xpub'] === self::XPUB
            && $request['count'] === 5);
    }

    public function test_a_malformed_key_is_rejected_before_it_reaches_the_watcher(): void
    {
        $token = $this->admin();

        foreach (['xprv6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz', 'nonsense', ''] as $candidate) {
            $this->assertInvalidField(
                $this->withToken($token)->postJson('/api/admin/wallets/tron/preview', ['xpub' => $candidate]),
                'xpub',
            );
        }

        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), '/addresses/derive-batch'));
    }

    public function test_a_key_the_watcher_rejects_becomes_a_field_error(): void
    {
        $this->resetHttpFakes();
        Http::fake([
            '*/addresses/derive-batch' => Http::response([
                'error' => [
                    'code' => 'invalid_xpub',
                    'message' => 'Invalid xpub.',
                    'details' => ['xpub' => ['expected account-level xpub (depth 3), got depth 4']],
                ],
            ], 422),
        ]);

        $response = $this->withToken($this->admin())
            ->postJson('/api/admin/wallets/tron/preview', ['xpub' => self::XPUB]);

        $this->assertInvalidField($response, 'xpub');
        $response->assertJsonPath('error.details.xpub.0', 'expected account-level xpub (depth 3), got depth 4');

        // The same key is refused on save, and nothing is written.
        $this->assertInvalidField(
            $this->withToken($this->admin())->putJson('/api/admin/wallets/tron', ['xpub' => self::XPUB]),
            'xpub',
        );
        $this->assertNull(Wallet::where('network_code', 'tron')->value('xpub'));
    }

    public function test_changing_a_key_after_addresses_were_issued_warns_and_keeps_the_index(): void
    {
        $this->withToken($this->admin())
            ->putJson('/api/admin/wallets/tron', ['xpub' => self::XPUB])
            ->assertOk()
            ->assertJsonMissingPath('data.warning');

        [$merchant, $key] = $this->makeMerchant();
        $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '5', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated();

        $this->assertSame(1, (int) Wallet::where('network_code', 'tron')->value('next_index'));

        $response = $this->withToken($this->admin())
            ->putJson('/api/admin/wallets/tron', ['xpub' => self::OTHER_XPUB])
            ->assertOk()
            ->assertJsonPath('data.addresses_issued', 1);

        $this->assertStringContainsString('1 deposit address(es) had already been issued', (string) $response->json('data.warning'));
        // The index continues rather than resetting; reusing 0 would hand the
        // same derivation slot out twice.
        $this->assertSame(1, (int) Wallet::where('network_code', 'tron')->value('next_index'));
        $this->assertSame(1, DepositAddress::where('network_code', 'tron')->count());
    }

    public function test_removing_the_key_falls_back_to_the_env_and_is_audited(): void
    {
        $token = $this->admin();

        $this->withToken($token)->putJson('/api/admin/wallets/tron', ['xpub' => self::XPUB])->assertOk();

        $this->withToken($token)->deleteJson('/api/admin/wallets/tron/xpub')
            ->assertOk()
            ->assertJsonPath('data.source', 'env')
            ->assertJsonPath('data.xpub_masked', null)
            ->assertJsonPath('data.label', null)
            ->assertJsonPath('data.xpub_set_at', null);

        $this->assertNull(Wallet::where('network_code', 'tron')->value('xpub'));

        $log = AuditLog::where('action', 'wallet.xpub_removed')->firstOrFail();
        $this->assertSame('xpub6CUGRU…3fDVmz', $log->changes['xpub_masked']);
    }

    public function test_allocation_sends_the_stored_key_in_the_derive_body(): void
    {
        $this->withToken($this->admin())
            ->putJson('/api/admin/wallets/tron', ['xpub' => self::XPUB])
            ->assertOk();

        [, $key] = $this->makeMerchant();

        $address = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '5', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated()
            ->json('data.address');

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/addresses/derive')
            && ! str_contains($request->url(), 'derive-batch')
            && $request['network'] === 'tron'
            && $request['index'] === 0
            && $request['xpub'] === self::XPUB);

        // The faked watcher seeds its address from the key, so a stored key
        // really did change where the money goes.
        $this->assertSame($this->fakeAddress('tron', 0, self::XPUB), $address);
        $this->assertNotSame($this->fakeAddress('tron', 0), $address);
    }

    public function test_allocation_omits_the_key_when_only_the_env_fallback_exists(): void
    {
        [, $key] = $this->makeMerchant();

        $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '5', 'currency' => 'USDT', 'network' => 'tron'])
            ->assertCreated();

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/addresses/derive')
            && ! str_contains($request->url(), 'derive-batch')
            && ! array_key_exists('xpub', (array) $request->data()));
    }

    public function test_an_unconfigured_network_is_hidden_from_every_picker(): void
    {
        $this->resetHttpFakes();
        // Only the EVM env key is loaded, so Tron has no wallet at all.
        $this->fakeWatcher(evmDerivation: true, tronDerivation: false);

        [, $key] = $this->makeMerchant();

        $networks = $this->withHeaders($this->keyHeaders($key))
            ->getJson('/api/v1/networks')->assertOk()->json('data');

        $this->assertSame(['bsc', 'ethereum'], collect($networks)->pluck('code')->sort()->values()->all());

        // The hosted checkout's `options` follow the same rule.
        $invoice = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '5'])
            ->assertCreated()
            ->json('data.id');

        $options = $this->getJson("/api/public/invoices/{$invoice}")->assertOk()->json('data.options');

        $this->assertNotContains('tron', collect($options)->pluck('network')->all());
        $this->assertContains('ethereum', collect($options)->pluck('network')->all());

        // And a payer cannot select it by hand either.
        $this->assertInvalidField(
            $this->postJson("/api/public/invoices/{$invoice}/select", ['currency' => 'USDT', 'network' => 'tron']),
            'network',
        );
    }

    public function test_creating_an_invoice_on_an_unconfigured_network_is_a_field_error(): void
    {
        $this->fakeWatcher(evmDerivation: true, tronDerivation: false);

        [, $key] = $this->makeMerchant();

        $response = $this->withHeaders($this->keyHeaders($key))
            ->postJson('/api/v1/invoices', ['amount' => '5', 'currency' => 'USDT', 'network' => 'tron']);

        $this->assertInvalidField($response, 'network');
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, (int) Wallet::where('network_code', 'tron')->value('next_index'));
    }

    public function test_allocation_refuses_when_the_wallet_disappears_mid_flight(): void
    {
        [$merchant] = $this->makeMerchant();

        $this->fakeWatcher(evmDerivation: false, tronDerivation: false);

        $this->expectException(WalletNotConfiguredException::class);

        app(AddressService::class)->allocate('tron', $merchant);
    }

    public function test_the_issued_addresses_listing_is_paginated_and_scoped_to_the_network(): void
    {
        [, $key] = $this->makeMerchant(['name' => 'Acme']);

        foreach (['tron', 'ethereum'] as $network) {
            $this->withHeaders($this->keyHeaders($key))
                ->postJson('/api/v1/invoices', ['amount' => '5', 'currency' => 'USDT', 'network' => $network])
                ->assertCreated();
        }

        $response = $this->withToken($this->admin())
            ->getJson('/api/admin/wallets/tron/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.derivation_index', 0)
            ->assertJsonPath('data.0.network', 'tron')
            ->assertJsonPath('data.0.merchant.name', 'Acme')
            ->assertJsonPath('data.0.received.USDT', '0.00')
            ->assertJsonPath('meta.total', 1);

        $this->assertNotNull($response->json('data.0.invoice_id'));
        $this->assertStringContainsString('tronscan.org', (string) $response->json('data.0.explorer_url'));
    }

    public function test_an_unknown_network_is_a_404(): void
    {
        $this->withToken($this->admin())->getJson('/api/admin/wallets/dogecoin/addresses')->assertNotFound();
        $this->withToken($this->admin())->putJson('/api/admin/wallets/dogecoin', ['xpub' => self::XPUB])->assertNotFound();
    }

    public function test_a_viewer_reads_wallets_but_cannot_change_them(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);
        $token = $this->adminToken($viewer);

        $this->withToken($token)->getJson('/api/admin/wallets')->assertOk();
        $this->forgetAuth()->withToken($token)->getJson('/api/admin/wallets/tron/addresses')->assertOk();

        $this->forgetAuth()->withToken($token)
            ->putJson('/api/admin/wallets/tron', ['xpub' => self::XPUB])
            ->assertForbidden();
        $this->forgetAuth()->withToken($token)
            ->postJson('/api/admin/wallets/tron/preview', ['xpub' => self::XPUB])
            ->assertForbidden();
        $this->forgetAuth()->withToken($token)
            ->deleteJson('/api/admin/wallets/tron/xpub')
            ->assertForbidden();

        $this->assertNull(Wallet::where('network_code', 'tron')->value('xpub'));
    }

    public function test_the_configured_verdict_is_cached_rather_than_re_probed(): void
    {
        $wallets = app(WalletService::class);

        $this->assertTrue($wallets->isConfigured('tron'));
        $this->assertTrue($wallets->isConfigured('tron'));
        $this->assertTrue($wallets->isConfigured('ethereum'));

        // One /health probe covers every network for the whole TTL: this feeds
        // the hosted checkout's `options`, which is polled every 5s.
        Http::assertSentCount(1);

        $wallets->forget();

        $this->assertTrue($wallets->isConfigured('tron'));
        Http::assertSentCount(2);
    }
}
