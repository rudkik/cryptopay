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
        'network_code', 'address', 'derivation_index', 'merchant_id', 'invoice_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'derivation_index' => 'integer',
            'is_active' => 'boolean',
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
}
