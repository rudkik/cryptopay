<?php

namespace App\Services;

use App\Enums\InvoiceType;
use App\Enums\TokenPurchaseStatus;
use App\Exceptions\InvalidStateException;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\TokenPurchaseResource;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Token;
use App\Models\TokenHolding;
use App\Models\TokenPurchase;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Token sale (SPEC §6.1). A purchase is always backed by an invoice; the token
 * price is frozen at creation time and USDT/USDC are treated as 1 USD.
 */
class TokenPurchaseService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly WebhookService $webhooks,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{purchase: TokenPurchase, invoice: Invoice}
     */
    public function create(Merchant $merchant, Token $token, array $data): array
    {
        if (! $token->is_active) {
            throw new InvalidStateException('This token is not available for purchase.');
        }

        $price = Money::normalize($token->price_usd);

        if (! Money::isPositive($price)) {
            throw new InvalidStateException('This token has no price set.');
        }

        [$tokenAmount, $payAmount] = $this->resolveAmounts($data, $price);

        $this->assertWithinLimits($token, $tokenAmount);

        $invoice = $this->invoices->create($merchant, [
            'amount' => $payAmount,
            'currency' => $data['currency'],
            'network' => $data['network'],
            'external_id' => $data['external_id'] ?? null,
            'description' => $data['description'] ?? sprintf('Purchase of %s %s', Money::trim($tokenAmount), $token->symbol),
            'customer_email' => $data['customer_email'] ?? null,
            'customer_id' => $data['customer_id'],
            'metadata' => $data['metadata'] ?? null,
            'success_url' => $data['success_url'] ?? null,
            'cancel_url' => $data['cancel_url'] ?? null,
            'expires_in' => $data['expires_in'] ?? 3600,
        ], InvoiceType::TokenPurchase);

        $purchase = TokenPurchase::create([
            'invoice_id' => $invoice->id,
            'token_id' => $token->id,
            'merchant_id' => $merchant->id,
            'customer_id' => (string) $data['customer_id'],
            'customer_email' => $data['customer_email'] ?? null,
            'token_amount' => $tokenAmount,
            'price_usd' => $price,
            'pay_amount' => $payAmount,
            'currency' => $data['currency'],
            'status' => TokenPurchaseStatus::Pending->value,
        ]);

        $purchase->setRelation('token', $token);
        $invoice->setRelation('tokenPurchase', $purchase);

        return ['purchase' => $purchase, 'invoice' => $invoice];
    }

    /**
     * Mirror the invoice status onto its purchase. Called from
     * InvoiceService::recalculate() after every status transition.
     */
    public function syncWithInvoice(Invoice $invoice): void
    {
        $purchase = $invoice->tokenPurchase()->first();

        if (! $purchase || $purchase->status !== TokenPurchaseStatus::Pending) {
            return;
        }

        if ($invoice->status->isPaid()) {
            $this->complete($purchase, $invoice);

            return;
        }

        $next = match ($invoice->status->value) {
            'expired', 'partially_paid' => TokenPurchaseStatus::Expired,
            'cancelled' => TokenPurchaseStatus::Cancelled,
            default => null,
        };

        if ($next) {
            $purchase->forceFill(['status' => $next->value])->save();
        }
    }

    public function completeForInvoice(Invoice $invoice): void
    {
        $this->syncWithInvoice($invoice);
    }

    /**
     * Credit the customer's holding and bump `tokens.sold`, exactly once, under
     * a row lock on the token.
     */
    public function complete(TokenPurchase $purchase, ?Invoice $invoice = null): TokenPurchase
    {
        $completed = DB::transaction(function () use ($purchase) {
            $fresh = TokenPurchase::query()->whereKey($purchase->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->status !== TokenPurchaseStatus::Pending) {
                return null;
            }

            $token = Token::query()->whereKey($fresh->token_id)->lockForUpdate()->first();

            if ($token) {
                $token->forceFill(['sold' => Money::add($token->sold, $fresh->token_amount)])->save();
            }

            $holding = TokenHolding::query()
                ->where('token_id', $fresh->token_id)
                ->where('customer_id', $fresh->customer_id)
                ->lockForUpdate()
                ->first();

            if ($holding) {
                $holding->forceFill(['amount' => Money::add($holding->amount, $fresh->token_amount)])->save();
            } else {
                TokenHolding::create([
                    'token_id' => $fresh->token_id,
                    'merchant_id' => $fresh->merchant_id,
                    'customer_id' => $fresh->customer_id,
                    'amount' => $fresh->token_amount,
                ]);
            }

            $fresh->forceFill([
                'status' => TokenPurchaseStatus::Completed->value,
                'completed_at' => now(),
            ])->save();

            return $fresh;
        });

        if (! $completed) {
            return $purchase->refresh();
        }

        $invoice ??= $completed->invoice()->first();

        if ($invoice) {
            $invoice->loadMissing('merchant');
            $completed->loadMissing('token');

            $this->webhooks->create($invoice->merchant, 'token_purchase.completed', [
                'invoice' => json_decode(json_encode((new InvoiceResource($invoice))->toArray(request()), WebhookService::JSON_FLAGS), true),
                'token_purchase' => json_decode(json_encode((new TokenPurchaseResource($completed))->toArray(request()), WebhookService::JSON_FLAGS), true),
            ], $invoice);
        }

        return $completed;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string} [token_amount, pay_amount]
     */
    private function resolveAmounts(array $data, string $price): array
    {
        if (isset($data['token_amount']) && $data['token_amount'] !== null && $data['token_amount'] !== '') {
            $tokenAmount = Money::normalize($data['token_amount']);
            $payAmount = Money::mul($tokenAmount, $price);
        } else {
            $payAmount = Money::normalize($data['pay_amount']);
            $tokenAmount = Money::div($payAmount, $price);
        }

        if (! Money::isPositive($tokenAmount) || ! Money::isPositive($payAmount)) {
            throw new InvalidStateException('The resulting purchase amount must be greater than zero.');
        }

        return [$tokenAmount, $payAmount];
    }

    private function assertWithinLimits(Token $token, string $tokenAmount): void
    {
        if (Money::isPositive($token->min_purchase) && Money::cmp($tokenAmount, $token->min_purchase) < 0) {
            throw new InvalidStateException(
                'The purchase is below the minimum for this token.',
                ['min_purchase' => [Money::trim($token->min_purchase)]],
            );
        }

        if ($token->max_purchase !== null && Money::isPositive($token->max_purchase) && Money::cmp($tokenAmount, $token->max_purchase) > 0) {
            throw new InvalidStateException(
                'The purchase exceeds the maximum for this token.',
                ['max_purchase' => [Money::trim($token->max_purchase)]],
            );
        }

        if ($token->total_supply !== null) {
            $remaining = Money::sub($token->total_supply, $token->sold);

            if (Money::cmp($tokenAmount, $remaining) > 0) {
                throw new InvalidStateException(
                    'Not enough token supply remains for this purchase.',
                    ['remaining' => [Money::trim($remaining)]],
                );
            }
        }
    }
}
