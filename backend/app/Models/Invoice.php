<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Support\Money;
use App\Support\MoneyCast;
use App\Support\NetworkRegistry;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id', 'type', 'external_id', 'currency', 'network_code',
        'deposit_address_id', 'amount', 'amount_received', 'amount_confirmed',
        'status', 'description', 'customer_email', 'customer_id', 'metadata',
        'success_url', 'cancel_url', 'expires_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'amount' => MoneyCast::class,
            'amount_received' => MoneyCast::class,
            'amount_confirmed' => MoneyCast::class,
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function depositAddress(): BelongsTo
    {
        return $this->belongsTo(DepositAddress::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'network_code', 'code');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function tokenPurchase(): HasOne
    {
        return $this->hasOne(TokenPurchase::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Contract for the invoice currency on the invoice network. */
    public function tokenContract(): ?TokenContract
    {
        return NetworkRegistry::make()->contract($this->network_code, $this->currency);
    }

    public function decimals(): int
    {
        return $this->tokenContract()?->decimals ?? 6;
    }

    /** EIP-681 payload for EVM networks, bare address for Tron (SPEC §6.1). */
    public function qrPayload(): string
    {
        $address = $this->depositAddress?->address ?? '';
        $contract = $this->tokenContract();
        $network = NetworkRegistry::make()->network($this->network_code);

        if (! $network || ! $network->isEvm() || ! $contract) {
            return $address;
        }

        $amountRaw = Money::toBaseUnits($this->amount, $contract->decimals);

        return sprintf(
            'ethereum:%s@%s/transfer?address=%s&uint256=%s',
            $contract->contract_address,
            $network->chain_id,
            $address,
            $amountRaw
        );
    }
}
