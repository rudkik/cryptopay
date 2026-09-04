<?php

namespace Tests\Feature\Api;

use App\Enums\TokenPurchaseStatus;
use App\Enums\TransactionStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\SendWebhookJob;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Network;
use App\Models\Token;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regressions for the findings of the backend correctness audit. Each case is
 * named after the behaviour it protects, not the bug, but the comment says what
 * used to happen.
 */
class AuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNetworks();
        $this->fakeWatcher();

        [$this->merchant, $this->key] = $this->makeMerchant([
            'webhook_url' => 'https://merchant.test/hooks',
        ]);
    }

    private function createInvoice(array $overrides = []): Invoice
    {
        $id = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/invoices', array_merge([
                'amount' => '100', 'currency' => 'USDT', 'network' => 'tron',
            ], $overrides))
            ->assertCreated()->json('data.id');

        return Invoice::with('depositAddress')->findOrFail($id);
    }

    private function ingest(Invoice $invoice, string $amount, string $status, string $seed = ''): void
    {
        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', [
            'network' => $invoice->network_code,
            'tx_hash' => $this->txHash($invoice->id.$amount.$status.$seed, $invoice->network_code),
            'log_index' => 0,
            'symbol' => $invoice->currency,
            'to_address' => $invoice->depositAddress->address,
            'amount' => $amount,
            'amount_raw' => bcmul($amount, '1000000', 0),
            'confirmations' => $status === 'confirmed' ? 19 : 1,
            'status' => $status,
        ])->assertOk();
    }

    // ---------------------------------------------------------------- expiry

    /**
     * Money that is on chain but not yet confirmed keeps the invoice open past
     * `expires_at` — the watcher still has to resolve it to confirmed or
     * orphaned. resolveStatus() already refused to move such an invoice, so the
     * sweep re-processed it every minute for nothing; sorted oldest-first and
     * capped at --limit, enough of them permanently starve the queue behind
     * them. They are now excluded in SQL, so the run makes real progress.
     */
    public function test_the_expiry_sweep_skips_an_invoice_whose_payment_is_still_confirming(): void
    {
        $waiting = $this->createInvoice();
        $this->ingest($waiting, '100', 'detected');

        $untouched = $this->createInvoice();

        Carbon::setTestNow(now()->addHours(2));

        $this->artisan('invoices:expire --limit=1')
            ->expectsOutputToContain('Processed 1 expiring invoice(s).')
            ->assertSuccessful();

        // The one with a pending transaction did not consume the single slot.
        $this->assertSame('confirming', $waiting->refresh()->status->value);
        $this->assertSame('expired', $untouched->refresh()->status->value);

        Carbon::setTestNow();
    }

    /**
     * A partially confirmed invoice that ALSO has a still-unconfirmed transfer
     * keeps waiting rather than closing as partially_paid: the pending transfer
     * may well complete it.
     */
    public function test_a_partly_confirmed_invoice_with_a_pending_transfer_keeps_waiting(): void
    {
        $invoice = $this->createInvoice();
        $this->ingest($invoice, '40', 'confirmed', 'a');
        $this->ingest($invoice, '60', 'detected', 'b');

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('confirming', $invoice->refresh()->status->value);
        $this->assertFalse(
            WebhookDelivery::where('event', 'invoice.partially_paid')->exists(),
            'The invoice was closed out while a transfer was still confirming.',
        );

        Carbon::setTestNow();
    }

    /**
     * A transfer in a currency the invoice is not denominated in never settles
     * it, so it must not hold its expiry open either.
     */
    public function test_a_pending_transfer_in_another_currency_does_not_hold_expiry_open(): void
    {
        $invoice = $this->createInvoice(['currency' => 'USDT']);

        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', [
            'network' => 'tron',
            'tx_hash' => $this->txHash('other-currency', 'tron'),
            'log_index' => 0,
            'symbol' => 'USDC',
            'to_address' => $invoice->depositAddress->address,
            'amount' => '100',
            'amount_raw' => '100000000',
            'confirmations' => 1,
            'status' => 'detected',
        ])->assertOk();

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('expired', $invoice->refresh()->status->value);

        Carbon::setTestNow();
    }

    // -------------------------------------------------------------- webhooks

    /**
     * WebhookService::create() already queues a job with next_attempt_at = now,
     * so the retry sweep used to queue a second, third and fourth job for the
     * same delivery while the first was still waiting its turn. Only deliveries
     * the queue has actually touched are due for a fresh dispatch.
     */
    public function test_the_retry_sweep_leaves_a_freshly_queued_delivery_alone(): void
    {
        $delivery = $this->pendingDelivery(attempts: 0, dueAt: now());

        Bus::fake();
        $this->artisan('webhooks:retry')->assertSuccessful();
        Bus::assertNotDispatched(SendWebhookJob::class);

        // One that has already failed an attempt and come due again is exactly
        // what the sweep exists for.
        $delivery->forceFill(['attempts' => 1, 'next_attempt_at' => now()->subMinute()])->save();

        $this->artisan('webhooks:retry')->assertSuccessful();
        Bus::assertDispatched(SendWebhookJob::class);
    }

    /**
     * A delivery whose original job vanished (worker killed, Redis flushed) is
     * still rescued, just not on the first sweep after it was created.
     */
    public function test_the_retry_sweep_rescues_a_never_attempted_delivery_that_was_stranded(): void
    {
        $this->pendingDelivery(attempts: 0, dueAt: now()->subMinutes(30));

        Bus::fake();
        $this->artisan('webhooks:retry')->assertSuccessful();
        Bus::assertDispatched(SendWebhookJob::class);
    }

    private function pendingDelivery(int $attempts, Carbon $dueAt): WebhookDelivery
    {
        $delivery = new WebhookDelivery;
        $delivery->forceFill([
            'merchant_id' => $this->merchant->id,
            'event' => 'invoice.paid',
            'url' => 'https://merchant.test/hooks',
            'payload' => '{"id":"x"}',
            'attempts' => $attempts,
            'max_attempts' => 6,
            'status' => WebhookDeliveryStatus::Pending->value,
            'next_attempt_at' => $dueAt,
        ])->save();

        return $delivery;
    }

    // -------------------------------------------------------- token purchases

    /**
     * Cancelling is the one status change that does not run through
     * recalculate(), so nothing carried the purchase along: a cancelled
     * token-sale invoice left its token_purchase on `pending` forever and the
     * `cancelled` state SPEC §4 defines was unreachable.
     */
    public function test_cancelling_a_token_sale_invoice_cancels_its_purchase(): void
    {
        config()->set('features.token_sale', true);

        $token = Token::factory()->create([
            'merchant_id' => $this->merchant->id,
            'symbol' => 'DEMO',
            'price_usd' => '2',
            'min_purchase' => '1',
        ]);

        $created = $this->withHeaders($this->keyHeaders($this->key))
            ->postJson('/api/v1/token-purchases', [
                'token_id' => $token->id,
                'token_amount' => '10',
                'customer_id' => 'cust-1',
                'currency' => 'USDT',
                'network' => 'tron',
            ])->assertCreated();

        $invoiceId = $created->json('invoice.id');
        $purchaseId = $created->json('purchase.id');

        $this->withHeaders($this->keyHeaders($this->key))
            ->postJson("/api/v1/invoices/{$invoiceId}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('token_purchases', [
            'id' => $purchaseId,
            'status' => TokenPurchaseStatus::Cancelled->value,
        ]);
    }

    // ------------------------------------------------------------- validation

    /**
     * `amount` reached Money::normalize(), which casts to string, so a JSON
     * array or object raised "Array to string conversion" and turned a bad
     * request into a 500 for any merchant key holder.
     */
    public function test_a_non_scalar_amount_is_a_validation_error_not_a_server_error(): void
    {
        foreach ([['1'], ['a' => 1]] as $bogus) {
            $response = $this->withHeaders($this->keyHeaders($this->key))
                ->postJson('/api/v1/invoices', ['amount' => $bogus, 'currency' => 'USDT', 'network' => 'tron']);

            $this->assertInvalidField($response, 'amount');
        }
    }

    /**
     * `merchant_id` is a uuid column: unvalidated, anything else reached
     * Postgres, raised 22P02, and came back as a 500 whose message carried the
     * SQL statement plus the database host, port and name. Reachable by a
     * viewer, and `?merchant_id=` is the documented call (SPEC §6.4).
     */
    public function test_admin_list_filters_reject_a_malformed_uuid(): void
    {
        $token = $this->adminToken(User::factory()->create());
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        foreach (['/api/admin/balances', '/api/admin/ledger'] as $uri) {
            $this->assertInvalidField(
                $this->forgetAuth()->withHeaders($headers)->getJson($uri.'?merchant_id=not-a-uuid'),
                'merchant_id',
            );
        }

        // Same class: `?q[]=x` reached Request::string() and threw.
        $this->assertInvalidField(
            $this->forgetAuth()->withHeaders($headers)->getJson('/api/admin/merchants?q[]=x'),
            'q',
        );
    }

    /**
     * `explorer_address_url` is echoed verbatim into the UNAUTHENTICATED public
     * invoice payload (SPEC §6.3), so it may only ever be http(s) — the same
     * rule SECURITY.md §4.1 records for success_url/cancel_url/webhook_url,
     * which these two templates were missed by.
     */
    public function test_explorer_url_templates_must_be_http(): void
    {
        $token = $this->adminToken(User::factory()->create());

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->putJson('/api/admin/networks/tron', ['explorer_address_url' => 'javascript:alert(1)']);

        $this->assertInvalidField($response, 'explorer_address_url');

        // A real template, placeholders and all, still saves.
        $this->forgetAuth()
            ->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->putJson('/api/admin/networks/tron', [
                'explorer_address_url' => 'https://tronscan.org/#/address/{address}',
            ])->assertOk();
    }

    // ------------------------------------------------------------- responses

    /**
     * MeController flattened the resource with toArray(), which skips
     * JsonResource's filter pass, so the unloaded `api_keys` relation survived
     * as a MissingValue and serialised as `"api_keys": {}`.
     */
    public function test_me_returns_the_merchant_and_webhook_settings_without_a_leaked_missing_value(): void
    {
        $data = $this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/me')->assertOk()->json('data');

        $this->assertSame([
            'id', 'name', 'email', 'webhook_url', 'is_active', 'settings',
            'underpayment_tolerance', 'created_at', 'balances', 'webhook', 'api_key',
        ], array_keys($data));

        $this->assertArrayNotHasKey('api_keys', $data);
        $this->assertArrayNotHasKey('webhook_secret', $data);
        $this->assertSame($this->merchant->id, $data['id']);
        $this->assertIsArray($data['balances']);
        $this->assertSame('cp_live_', substr((string) $data['api_key']['key_prefix'], 0, 8));
    }

    /** Same leak on the admin webhook-secret rotation response. */
    public function test_rotating_a_webhook_secret_returns_a_clean_merchant_object(): void
    {
        $token = $this->adminToken(User::factory()->create());

        $merchant = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson("/api/admin/merchants/{$this->merchant->id}/webhook-secret/rotate")
            ->assertOk()->json('merchant');

        $this->assertArrayNotHasKey('api_keys', $merchant);
        $this->assertArrayNotHasKey('balances', $merchant);
    }

    /**
     * `GET /api/public/config` advertised every enabled network while
     * `GET /api/v1/networks` filtered out the ones with no deposit wallet, so a
     * client reading both saw two different answers for the same question.
     */
    public function test_public_config_only_lists_networks_that_can_take_money(): void
    {
        $this->fakeWatcher(evmDerivation: false, tronDerivation: true);

        $this->getJson('/api/public/config')
            ->assertOk()
            ->assertJsonPath('networks', ['tron']);

        $codes = collect($this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/networks')->json('data'))->pluck('code')->all();

        $this->assertSame(['tron'], $codes);
    }

    // ---------------------------------------------------------------- wallets

    /**
     * Re-saving the key that is already stored (changing only the label, or a
     * double click) changes nothing about where the money goes, so the
     * "addresses were issued from the previous key" warning was a false alarm —
     * and that warning is the one thing on the page that has to be believed.
     */
    public function test_saving_an_unchanged_xpub_does_not_warn_about_the_previous_key(): void
    {
        $token = $this->adminToken(User::factory()->create());
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $xpub = 'xpub'.str_repeat('A', 100);

        $this->withHeaders($headers)->putJson('/api/admin/wallets/tron', ['xpub' => $xpub])->assertOk();

        // Issue an address so the warning has something to warn about.
        app(WalletService::class)->forget();
        $this->createInvoice();

        $again = $this->forgetAuth()->withHeaders($headers)
            ->putJson('/api/admin/wallets/tron', ['xpub' => $xpub, 'label' => 'Cold wallet'])
            ->assertOk();

        $this->assertArrayNotHasKey('warning', $again->json('data'));

        // A genuinely different key still warns.
        $changed = $this->forgetAuth()->withHeaders($headers)
            ->putJson('/api/admin/wallets/tron', ['xpub' => 'xpub'.str_repeat('B', 100)])
            ->assertOk();

        $this->assertArrayHasKey('warning', $changed->json('data'));
    }

    // ------------------------------------------------------------------ money

    /**
     * toBaseUnits() truncates and format() rounds half-up, so the simulator
     * built pairs that disagreed: 1.9999995 on a 6-decimal token became
     * "2.000000" / 1999999 — a combination StoreTransactionRequest rejects
     * outright when the watcher sends it, stored here as a transaction whose
     * human amount was not its on-chain amount.
     */
    public function test_a_simulated_payment_stores_one_number_not_two(): void
    {
        $invoice = $this->createInvoice();
        $token = $this->adminToken(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment", ['amount' => '1.9999995'])
            ->assertOk();

        $transaction = Transaction::firstOrFail();

        $this->assertSame(
            $transaction->amount_raw,
            Money::toBaseUnits($transaction->amount, 6),
            'amount and amount_raw describe different amounts of money.',
        );
        $this->assertSame('1999999', $transaction->amount_raw);
    }

    /** An amount too small to exist on chain is refused, not silently zeroed. */
    public function test_a_simulated_payment_below_one_base_unit_is_rejected(): void
    {
        $invoice = $this->createInvoice();
        $token = $this->adminToken(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson("/api/admin/invoices/{$invoice->id}/simulate-payment", ['amount' => '0.0000001'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state');

        $this->assertSame(0, Transaction::count());
    }

    /**
     * Exact payment of an amount carried at the token's full precision settles
     * the invoice. Comparisons run at scale 18 on the stored value; only
     * presentation is rounded.
     */
    public function test_an_exact_payment_at_full_token_precision_is_paid(): void
    {
        $invoice = $this->createInvoice(['amount' => '10.000001']);

        $this->ingest($invoice, '10.000001', 'confirmed');

        $this->assertSame('paid', $invoice->refresh()->status->value);
    }

    /**
     * 18-decimal amounts (BSC USDT/USDC) survive the whole round trip: stored
     * amount, amount_raw, and the EIP-681 uint256 in the QR payload all agree.
     */
    public function test_eighteen_decimal_amounts_survive_the_round_trip(): void
    {
        $invoice = $this->createInvoice(['amount' => '0.123456789012345678', 'network' => 'bsc']);

        $this->withHeaders($this->internalHeaders())->postJson('/api/internal/transactions', [
            'network' => 'bsc',
            'tx_hash' => $this->txHash('bsc-18', 'bsc'),
            'log_index' => 0,
            'symbol' => 'USDT',
            'to_address' => $invoice->depositAddress->address,
            'amount' => '0.123456789012345678',
            'amount_raw' => '123456789012345678',
            'confirmations' => 15,
            'status' => 'confirmed',
        ])->assertOk();

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status->value);

        $body = $this->withHeaders($this->keyHeaders($this->key))
            ->getJson("/api/v1/invoices/{$invoice->id}")->json('data');

        // `amount_raw` is a string column, so it is exact on every driver: this
        // is the integer the watcher decoded from the chain.
        $this->assertSame('123456789012345678', $body['transactions'][0]['amount_raw']);

        // Everything else on this path is read back out of a decimal(36,18)
        // column, and only a driver with a real decimal type returns it
        // unchanged. SQLite gives those columns float affinity and hands back
        // 0.123456789012345677; Postgres, which is what production runs, is
        // exact — verified end to end against the running stack, where the
        // stored amount, amount_confirmed and the EIP-681 uint256 all came back
        // as 0.123456789012345678 / 123456789012345678. Asserting it under
        // SQLite would be asserting something the driver cannot do.
        if ($this->app['db']->connection()->getDriverName() !== 'sqlite') {
            $this->assertSame(0, Money::cmp($invoice->amount_confirmed, '0.123456789012345678'));
            $this->assertSame('0.123456789012345678', $body['amount']);
            $this->assertSame('0.123456789012345678', $body['transactions'][0]['amount']);
            $this->assertStringContainsString('uint256=123456789012345678', (string) $body['qr_payload']);
        }
    }

    // ---------------------------------------------------------- idempotency

    /**
     * The middleware now serialises same-key requests instead of only
     * de-duplicating the ones that arrive after a response was cached — a
     * client retrying on a timeout usually retries while the first request is
     * still running. What is asserted here is that the lock is always handed
     * back: a leaked lock would make every later retry of that key wait out the
     * full block() timeout.
     */
    public function test_the_idempotency_lock_is_released_and_the_response_replayed(): void
    {
        $headers = $this->keyHeaders($this->key, ['Idempotency-Key' => 'audit-key-1']);
        $body = ['amount' => '42', 'currency' => 'USDT', 'network' => 'tron'];

        $first = $this->withHeaders($headers)->postJson('/api/v1/invoices', $body)->assertCreated();
        $second = $this->withHeaders($headers)->postJson('/api/v1/invoices', $body)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Invoice::where('amount', Money::normalize('42'))->count());
        $second->assertHeader('Idempotent-Replay', 'true');

        $lock = Cache::lock($this->idempotencyLockKey('audit-key-1'), 5);
        $this->assertTrue($lock->get(), 'The idempotency lock was never released.');
        $lock->release();
    }

    private function idempotencyLockKey(string $key): string
    {
        return 'idempotency:'.hash('sha256', implode('|', [
            $this->merchant->id, 'POST', 'api/v1/invoices', $key,
        ])).':lock';
    }

    // -------------------------------------------------------------- scheduler

    /**
     * withoutOverlapping() defaults to a 24-hour lock: a scheduler container
     * killed mid-run would block invoice expiry — and therefore its webhooks —
     * for a full day.
     */
    public function test_scheduled_commands_use_a_short_overlap_lock(): void
    {
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'invoices:expire')
                || str_contains((string) $event->command, 'webhooks:retry')
                || str_contains((string) $event->command, 'networks:health'));

        $this->assertCount(3, $events);

        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping, "[{$event->command}] may overlap itself.");
            $this->assertLessThanOrEqual(
                10,
                $event->expiresAt,
                "[{$event->command}] would hold its lock for {$event->expiresAt} minutes after a crash.",
            );
        }
    }

    // ------------------------------------------------------------------ state

    /** A detected transfer never credits the balance; only `confirmed` does. */
    public function test_a_detected_transfer_is_pending_not_available(): void
    {
        $invoice = $this->createInvoice();
        $this->ingest($invoice, '100', 'detected');

        $balance = $this->withHeaders($this->keyHeaders($this->key))
            ->getJson('/api/v1/balances')->assertOk()->json('data.0');

        $this->assertSame(0, Money::cmp($balance['available'], '0'));
        $this->assertSame(0, Money::cmp($balance['pending'], '100'));
        $this->assertSame(0, Transaction::whereNotNull('credited_at')->count());
    }

    /** Networks and their confirmation counts come from the database (SPEC §2). */
    public function test_confirmations_required_is_read_from_the_network_row(): void
    {
        $this->assertSame(19, Network::where('code', 'tron')->value('confirmations_required'));

        $invoice = $this->createInvoice();
        $this->ingest($invoice, '100', 'detected');

        $tx = $this->withHeaders($this->keyHeaders($this->key))
            ->getJson("/api/v1/invoices/{$invoice->id}")->json('data.transactions.0');

        $this->assertSame(19, $tx['confirmations_required']);
        $this->assertSame(TransactionStatus::Detected->value, $tx['status']);
    }
}
