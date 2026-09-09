<?php

namespace App\Models;

use Database\Factories\DepositAddressFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositAddress extends Model
{
    /** @use HasFactory<DepositAddressFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'network_code', 'address', 'derivation_index', 'receiving_address_id', 'merchant_id', 'invoice_id',
        'is_active', 'leased_until',
    ];

    protected function casts(): array
    {
        return [
            'derivation_index' => 'integer',
            'is_active' => 'boolean',
            'leased_until' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'network_code', 'code');
    }

    /** Set when this row is the lease record of a pooled receiving address. */
    public function receivingAddress(): BelongsTo
    {
        return $this->belongsTo(ReceivingAddress::class);
    }

    public function isPooled(): bool
    {
        return $this->receiving_address_id !== null;
    }

    /** A pooled address is busy while its lease runs; a derived one is never reused. */
    public function isLeased(): bool
    {
        return $this->leased_until !== null && $this->leased_until->isFuture();
    }
}
