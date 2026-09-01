<?php

namespace App\Models;

use App\Support\MoneyCast;
use Database\Factories\BalanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Balance extends Model
{
    /** @use HasFactory<BalanceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id', 'currency', 'network_code', 'available', 'pending',
    ];

    protected function casts(): array
    {
        return [
            'available' => MoneyCast::class,
            'pending' => MoneyCast::class,
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
