<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'email', 'webhook_url', 'webhook_secret', 'is_active', 'settings',
    ];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Underpayment tolerance in percent (SPEC §5), default 0.5%. */
    public function underpaymentTolerance(): string
    {
        $value = data_get($this->settings, 'underpayment_tolerance', 0.5);

        return Money::normalize($value);
    }
}
