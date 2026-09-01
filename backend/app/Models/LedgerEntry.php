<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Support\MoneyCast;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id', 'currency', 'network_code', 'amount', 'type',
        'transaction_id', 'balance_after', 'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'balance_after' => MoneyCast::class,
            'type' => LedgerEntryType::class,
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
