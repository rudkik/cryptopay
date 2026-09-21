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

    public const DEFAULT_INVOICE_TTL = 3600;

    public const MIN_INVOICE_TTL = 60;

    public const MAX_INVOICE_TTL = 86400;

    /**
     * How long a new invoice stays payable when the API call does not pass
     * `expires_in`, in seconds. Set per service in the admin (Settings →
     * "Payment window"); default one hour.
     */
    public function invoiceTtl(): int
    {
        $value = (int) data_get($this->settings, 'invoice_ttl', self::DEFAULT_INVOICE_TTL);

        return max(self::MIN_INVOICE_TTL, min(self::MAX_INVOICE_TTL, $value ?: self::DEFAULT_INVOICE_TTL));
    }

    /** Underpayment tolerance in percent (SPEC §5), default 0.5%. */
    public function underpaymentTolerance(): string
    {
        $value = data_get($this->settings, 'underpayment_tolerance', 0.5);

        return Money::normalize($value);
    }
}
