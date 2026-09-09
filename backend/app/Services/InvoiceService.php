<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NotFoundException;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Support\Money;
use App\Support\NetworkRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Invoice lifecycle and the SPEC §5 status machine.
 */
class InvoiceService
{
    public function __construct(
        private readonly AddressService $addresses,
        private readonly WebhookService $webhooks,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * `currency` and `network` are optional and travel together: omit both and
     * the invoice is created with nothing selected and no deposit address, for
     * the payer to choose on the hosted checkout (SPEC §6.3).
     *
     * @param  array{amount: string, currency?: ?string, network?: ?string, external_id?: ?string,
     *   description?: ?string, customer_email?: ?string, customer_id?: ?string, metadata?: ?array,
     *   success_url?: ?string, cancel_url?: ?string, expires_in?: ?int}  $data
     */
    public function create(Merchant $merchant, array $data, InvoiceType $type = InvoiceType::Payment): Invoice
    {
        $networkCode = $data['network'] ?? null;
        $currency = $data['currency'] ?? null;

        $depositAddress = null;
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        if ($networkCode !== null && $currency !== null) {
            $this->assertPairAvailable($networkCode, $currency);

            // Allocated outside the invoice transaction: it may perform an HTTP
            // call to the watcher and holds row locks for its duration.
            $depositAddress = $this->addresses->allocate(
                $networkCode,
                $merchant,
                $currency,
                $this->leaseSeconds($expiresIn),
            );
        }

        return DB::transaction(function () use ($merchant, $data, $type, $depositAddress, $networkCode, $currency, $expiresIn) {
            $invoice = Invoice::create([
                'merchant_id' => $merchant->id,
                'type' => $type->value,
                'external_id' => $data['external_id'] ?? null,
                'currency' => $currency,
                'network_code' => $networkCode,
                'deposit_address_id' => $depositAddress?->id,
                'amount' => Money::normalize($data['amount']),
                'amount_received' => Money::zero(),
                'amount_confirmed' => Money::zero(),
                'status' => InvoiceStatus::Pending->value,
                'description' => $data['description'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'success_url' => $data['success_url'] ?? null,
                'cancel_url' => $data['cancel_url'] ?? null,
                'expires_at' => now()->addSeconds($expiresIn),
            ]);

            // A derived address belongs to this invoice forever; a leased
            // static one belongs to it until the lease runs out.
            $depositAddress?->forceFill(['invoice_id' => $invoice->id])->save();

            $invoice->setRelation('depositAddress', $depositAddress);
            $invoice->setRelation('merchant', $merchant);

            return $invoice;
        });
    }

    /**
     * Record the currency and network a payer (or the merchant's own UI) picked
     * for an invoice created without them, and allocate its deposit address.
     *
     * At most once per invoice, by construction: the invoice row is locked and
     * re-checked *inside* the transaction, and the watcher round-trip happens
     * under that lock. A concurrent double-submit therefore blocks rather than
     * racing, and the loser sees the invoice already selected and gets a 409 —
     * the alternative (checking first, allocating after) would hand out two
     * addresses and orphan one of them, along with its derivation index.
     */
    public function selectNetwork(Invoice $invoice, string $currency, string $networkCode): Invoice
    {
        // Cheap rejections first, so an unavailable pair never reaches the lock.
        $this->assertPairAvailable($networkCode, $currency);
        $this->assertSelectable($invoice);

        $selected = DB::transaction(function () use ($invoice, $currency, $networkCode) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new NotFoundException;
            }

            $this->assertSelectable($locked);

            $locked->loadMissing('merchant');

            $depositAddress = $this->addresses->allocate(
                $networkCode,
                $locked->merchant,
                $currency,
                $this->leaseSeconds(max(0, now()->diffInSeconds($locked->expires_at, false))),
            );

            $locked->forceFill([
                'currency' => $currency,
                'network_code' => $networkCode,
                'deposit_address_id' => $depositAddress->id,
            ])->save();

            // A derived address belongs to this invoice forever; a leased
            // static one belongs to it until the lease runs out.
            $depositAddress->forceFill(['invoice_id' => $locked->id])->save();

            $locked->setRelation('depositAddress', $depositAddress);

            return $locked;
        });

        // A token purchase is quoted in its invoice's currency (SPEC §4).
        if ($selected->type === InvoiceType::TokenPurchase) {
            $selected->tokenPurchase()->update(['currency' => $currency]);
        }

        return $selected;
    }

    /**
     * How long a static receiving address is reserved for an invoice: its
     * remaining lifetime plus a grace period for payments that land late.
     */
    private function leaseSeconds(int $lifetime): int
    {
        return $lifetime + (int) config('services.wallet.lease_grace', 1800);
    }

    /** The network is live and the currency actually trades on it (SPEC §2). */
    private function assertPairAvailable(string $networkCode, string $currency): void
    {
        $registry = NetworkRegistry::make();
        $network = $registry->network($networkCode);

        if (! $network || ! $network->is_enabled) {
            throw new InvalidStateException("Network [{$networkCode}] is not available.");
        }

        $contract = $registry->contract($networkCode, $currency);

        if (! $contract || ! $contract->is_enabled) {
            throw new InvalidStateException("{$currency} is not available on [{$networkCode}].");
        }
    }

    private function assertSelectable(Invoice $invoice): void
    {
        if ($invoice->deposit_address_id !== null) {
            throw new InvalidStateException(
                'The currency and network for this invoice have already been selected.',
                ['status' => [$invoice->status->value]],
            );
        }

        if ($invoice->status !== InvoiceStatus::Pending) {
            throw new InvalidStateException(
                "Only pending invoices accept a currency and network; this one is [{$invoice->status->value}].",
                ['status' => [$invoice->status->value]],
            );
        }

        if ($invoice->isExpired()) {
            throw new InvalidStateException(
                'This invoice has expired and no longer accepts a currency and network.',
                ['status' => [$invoice->status->value]],
            );
        }
    }

    /**
     * SPEC §5: only a pending invoice can be cancelled.
     *
     * The check runs against the locked row, not the caller's copy, for the
     * same reason recalculate() does: a merchant retrying a cancel, or a
     * cancel racing the first incoming transaction, would otherwise both pass a
     * stale `status === pending` test and emit two `invoice.cancelled`
     * deliveries — or cancel an invoice that had already started confirming.
     */
    public function cancel(Invoice $invoice): Invoice
    {
        DB::transaction(function () use ($invoice) {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->first();

            if (! $locked) {
                throw new NotFoundException;
            }

            $invoice->setRawAttributes($locked->getAttributes(), sync: true);

            if ($invoice->status !== InvoiceStatus::Pending) {
                throw new InvalidStateException(
                    "Only pending invoices can be cancelled; this one is [{$invoice->status->value}].",
                    ['status' => [$invoice->status->value]],
                );
            }

            $invoice->forceFill(['status' => InvoiceStatus::Cancelled->value])->save();

            // A leased static address goes back to the pool right away.
            $this->addresses->release($invoice);
        });

        // Cancelling is the one status change that does not go through
        // recalculate(), so it has to carry the purchase along itself —
        // otherwise a cancelled token-sale invoice leaves its token_purchase
        // stuck on `pending` forever, and `cancelled` (SPEC §4) is a state the
        // table can never actually reach.
        if ($invoice->type === InvoiceType::TokenPurchase) {
            app(TokenPurchaseService::class)->syncWithInvoice($invoice);
        }

        $this->webhooks->dispatchInvoiceEvent(InvoiceStatus::Cancelled->webhookEvent(), $invoice);

        return $invoice;
    }

    /**
     * Apply expiry to an invoice whose `expires_at` has passed (SPEC §5):
     * nothing received -> expired, confirmed but short -> partially_paid.
     */
    public function expire(Invoice $invoice): Invoice
    {
        return $this->recalculate($invoice, expiring: true);
    }

    /**
     * Recompute received/confirmed totals and the status from the invoice's
     * transactions, persist any change, and emit the matching webhook.
     *
     * `$reversal` is set by TransactionIngestService when the recalculation was
     * triggered by a confirmed transaction going `orphaned`/`failed`. It is the
     * only way an invoice can *lose* settled money, so it is also the only way
     * `invoice.reversed` is emitted — an expiry sweep or a fresh payment never
     * produces one.
     *
     * The whole decision happens under a row lock on the invoice, because the
     * question "did the status change?" is what gates the webhook, and it can
     * only be answered against the committed row. Two watcher payloads for the
     * same invoice routinely arrive at once — two transactions confirming
     * together, or a confirmation racing the expiry sweep — and each caller
     * arrives holding an Invoice instance loaded *before* the other one
     * committed. Without the lock both read `status = confirming`, both compute
     * `paid`, and the merchant gets two `invoice.paid` deliveries for one
     * payment. Under the lock the loser re-reads `paid`, sees no transition and
     * stays quiet.
     *
     * @param  array{transaction_id: string, tx_hash: string, amount: string, reason: string}|null  $reversal
     */
    public function recalculate(Invoice $invoice, bool $expiring = false, ?array $reversal = null): Invoice
    {
        $previous = null;
        $reversed = false;

        $transition = DB::transaction(function () use ($invoice, $expiring, $reversal, &$previous, &$reversed) {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return null;
            }

            // Adopt the committed state without discarding the caller's loaded
            // relations (merchant, depositAddress), which the webhook payload
            // needs and which a refresh() here would only re-query.
            $invoice->setRawAttributes($locked->getAttributes(), sync: true);

            // Only transactions in the invoice's own currency pay it. A USDC
            // transfer to a USDT invoice's deposit address is still recorded and
            // still credited to the merchant's USDC balance (see
            // TransactionIngestService::credit()), but it must never settle a USDT
            // invoice — the two are not interchangeable just because they are both
            // "about a dollar".
            //
            // An invoice with no currency has no deposit address either, so nothing
            // can ever have been sent to it: skip the query rather than let
            // `where('currency', null)` become `currency is null` and match rows by
            // accident.
            $totals = $invoice->currency === null
                ? collect()
                : $invoice->transactions()
                    ->where('currency', $invoice->currency)
                    ->selectRaw('status, count(*) as cnt, sum(amount) as total')
                    ->groupBy('status')
                    ->get()
                    ->keyBy(fn ($row) => is_string($row->status) ? $row->status : $row->status->value);

            $confirmed = Money::normalize($totals->get(TransactionStatus::Confirmed->value)?->total ?? 0);
            $detected = Money::normalize($totals->get(TransactionStatus::Detected->value)?->total ?? 0);
            $received = Money::add($confirmed, $detected);

            $previous = $invoice->status;
            $next = $this->resolveStatus($invoice, $confirmed, $received, $expiring);

            // Decided under the same lock as the transition itself: whoever
            // wins the race is the one caller that saw the invoice stop being
            // paid, so exactly one `invoice.reversed` goes out.
            $reversed = $reversal !== null && $next !== $previous && $this->isReversal($previous, $next);

            $changes = [
                'amount_received' => $received,
                'amount_confirmed' => $confirmed,
            ];

            if ($next !== $previous) {
                $changes['status'] = $next->value;
            }

            if ($next->isPaid() && $invoice->paid_at === null) {
                $changes['paid_at'] = now();
            } elseif (! $next->isPaid() && $invoice->paid_at !== null) {
                // A reorg took the payment back.
                $changes['paid_at'] = null;
            }

            $invoice->forceFill($changes)->save();

            return $next === $previous ? null : $next;
        });

        if ($transition !== null) {
            // Outside the lock: completing a token purchase takes its own locks
            // and the webhook dispatch queues a job, neither of which belongs
            // inside a transaction the whole invoice waits on. Exactly one
            // caller reaches this branch, because the transition was decided
            // against the locked row.
            if ($invoice->type === InvoiceType::TokenPurchase) {
                app(TokenPurchaseService::class)->syncWithInvoice($invoice);
            }

            if ($reversed) {
                // The plain status event is deliberately *not* sent alongside:
                // a bare `invoice.confirming` (or nothing at all, for a return
                // to `pending`) after an `invoice.paid` reads like a second
                // payment starting rather than the first one being undone.
                // `invoice.reversed` carries the fresh invoice plus what was
                // taken back, which is everything the merchant needs.
                $this->audit->log('invoice.reversed', $invoice, [
                    'status' => ['from' => $previous?->value, 'to' => $transition->value],
                    'reversal' => $reversal,
                ]);

                $this->webhooks->dispatchInvoiceEvent(
                    WebhookService::INVOICE_REVERSED,
                    $invoice->refresh(),
                    extra: ['reversal' => $reversal],
                );
            } elseif ($transition->emitsWebhook()) {
                $this->webhooks->dispatchInvoiceEvent($transition->webhookEvent(), $invoice->refresh());
            }
        }

        return $invoice;
    }

    /**
     * The invoice held settled money and no longer counts as paid.
     *
     * `paid` -> `partially_paid` counts: part of what was credited went away
     * and the merchant has to give back the difference. `overpaid` -> `paid`
     * does not: the invoice is still paid, and the surviving transaction still
     * covers it.
     */
    private function isReversal(InvoiceStatus $previous, InvoiceStatus $next): bool
    {
        $settled = [InvoiceStatus::Paid, InvoiceStatus::Overpaid, InvoiceStatus::PartiallyPaid];

        return in_array($previous, $settled, true) && ! $next->isPaid();
    }

    /**
     * The SPEC §5 state machine. Late payments to an expired or cancelled
     * invoice still move it to paid/partially_paid.
     */
    private function resolveStatus(Invoice $invoice, string $confirmed, string $received, bool $expiring): InvoiceStatus
    {
        $current = $invoice->status;
        $amount = Money::normalize($invoice->amount);
        $expired = $expiring || $invoice->isExpired();

        if (Money::isPositive($confirmed)) {
            if (Money::cmp($confirmed, $amount) > 0) {
                return InvoiceStatus::Overpaid;
            }

            if (Money::cmp($confirmed, $this->paymentThreshold($invoice)) >= 0) {
                return InvoiceStatus::Paid;
            }

            return $expired ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Confirming;
        }

        if (Money::isPositive($received)) {
            // Detected but not yet confirmed: hold the invoice open so the
            // confirmation can still land, unless it was explicitly cancelled.
            if ($current === InvoiceStatus::Cancelled) {
                return InvoiceStatus::Cancelled;
            }

            return $current === InvoiceStatus::Expired ? InvoiceStatus::Expired : InvoiceStatus::Confirming;
        }

        if ($expired && $current->isOpen()) {
            return InvoiceStatus::Expired;
        }

        // Nothing is on chain any more — every transaction was orphaned by a
        // reorg. Fall back to the invoice's pre-payment state.
        if (in_array($current, [InvoiceStatus::Paid, InvoiceStatus::Overpaid, InvoiceStatus::PartiallyPaid, InvoiceStatus::Confirming], true)) {
            return $expired ? InvoiceStatus::Expired : InvoiceStatus::Pending;
        }

        return $current;
    }

    /** amount minus the merchant's underpayment tolerance (percent, SPEC §5). */
    public function paymentThreshold(Invoice $invoice): string
    {
        $invoice->loadMissing('merchant');

        $tolerance = $invoice->merchant?->underpaymentTolerance() ?? '0.5';
        $amount = Money::normalize($invoice->amount);

        $allowance = Money::div(Money::mul($amount, $tolerance), '100');

        return Money::sub($amount, $allowance);
    }
}
