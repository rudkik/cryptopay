<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Support\Money;
use App\Support\NetworkRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hosted-checkout view (SPEC §6.3): the Invoice object minus `metadata` and
 * `customer_*`, plus network/contract display data.
 *
 * @mixin Invoice
 */
class PublicInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $registry = NetworkRegistry::make();
        $network = $registry->network($this->network_code);
        $contract = $registry->contract($this->network_code, $this->currency);
        $decimals = $contract?->decimals ?? 6;
        $address = $this->depositAddress?->address;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'external_id' => $this->external_id,
            'status' => $this->status->value,
            'is_paid' => $this->status->isPaid(),
            'currency' => $this->currency,
            'network' => $this->network_code,
            'network_name' => $network?->name,
            'amount' => Money::format($this->amount, $decimals),
            'amount_received' => Money::format($this->amount_received, $decimals),
            'amount_confirmed' => Money::format($this->amount_confirmed, $decimals),
            'address' => $address,
            'payment_url' => rtrim((string) config('app.url'), '/').'/pay/'.$this->id,
            'qr_payload' => $this->qrPayload(),
            'explorer_address_url' => $network?->explorerAddressUrl($address),
            'token_contract' => $contract ? [
                'symbol' => $contract->symbol,
                'contract_address' => $contract->contract_address,
                'decimals' => $contract->decimals,
            ] : null,
            'description' => $this->description,
            'success_url' => $this->success_url,
            'cancel_url' => $this->cancel_url,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'confirmations_required' => $registry->confirmationsRequired($this->network_code),
            'transactions' => TransactionResource::collection(
                $this->relationLoaded('transactions') ? $this->transactions : $this->transactions()->orderBy('created_at')->get()
            ),
            'token_purchase' => $this->publicTokenPurchase(),
        ];
    }

    private function publicTokenPurchase(): ?array
    {
        $purchase = $this->relationLoaded('tokenPurchase') ? $this->tokenPurchase : $this->tokenPurchase()->first();

        if (! $purchase) {
            return null;
        }

        $purchase->loadMissing('token');

        return [
            'id' => $purchase->id,
            'status' => $purchase->status->value,
            'token_amount' => Money::format($purchase->token_amount, $purchase->token?->decimals ?? 18),
            'price_usd' => Money::trim($purchase->price_usd),
            'token' => $purchase->token ? [
                'symbol' => $purchase->token->symbol,
                'name' => $purchase->token->name,
                'image_url' => $purchase->token->image_url,
            ] : null,
        ];
    }
}
