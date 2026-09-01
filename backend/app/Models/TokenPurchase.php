<?php

namespace App\Models;

use App\Enums\TokenPurchaseStatus;
use App\Support\MoneyCast;
use Database\Factories\TokenPurchaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenPurchase extends Model
{
    /** @use HasFactory<TokenPurchaseFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'invoice_id', 'token_id', 'merchant_id', 'customer_id', 'customer_email',
        'token_amount', 'price_usd', 'pay_amount', 'currency', 'status', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'token_amount' => MoneyCast::class,
            'price_usd' => MoneyCast::class,
            'pay_amount' => MoneyCast::class,
            'status' => TokenPurchaseStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
