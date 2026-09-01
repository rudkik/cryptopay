<?php

namespace App\Models;

use App\Support\MoneyCast;
use Database\Factories\TokenHoldingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenHolding extends Model
{
    /** @use HasFactory<TokenHoldingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['token_id', 'merchant_id', 'customer_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
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
