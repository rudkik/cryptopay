<?php

namespace App\Models;

use App\Support\MoneyCast;
use Database\Factories\TokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Token extends Model
{
    /** @use HasFactory<TokenFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id', 'symbol', 'name', 'description', 'price_usd', 'decimals',
        'total_supply', 'sold', 'min_purchase', 'max_purchase', 'is_active', 'image_url',
    ];

    protected function casts(): array
    {
        return [
            'price_usd' => MoneyCast::class,
            'total_supply' => MoneyCast::class,
            'sold' => MoneyCast::class,
            'min_purchase' => MoneyCast::class,
            'max_purchase' => MoneyCast::class,
            'decimals' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(TokenPurchase::class);
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(TokenHolding::class);
    }
}
