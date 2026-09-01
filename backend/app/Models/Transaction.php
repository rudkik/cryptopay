<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Support\MoneyCast;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'invoice_id', 'deposit_address_id', 'merchant_id', 'network_code',
        'tx_hash', 'log_index', 'from_address', 'to_address', 'currency',
        'contract_address', 'amount', 'amount_raw', 'block_number', 'block_hash',
        'confirmations', 'status', 'credited_at', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'log_index' => 'integer',
            'block_number' => 'integer',
            'confirmations' => 'integer',
            'status' => TransactionStatus::class,
            'credited_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
}
