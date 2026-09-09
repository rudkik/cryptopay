<?php

namespace App\Models;

use App\Enums\NetworkCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One operator-supplied "pay here" address: which network it lives on and
 * which currencies it may be offered for. Invoices lease it through its
 * `deposit_addresses` row (see AddressService::allocate()).
 */
class ReceivingAddress extends Model
{
    use HasUuids;

    protected $fillable = [
        'network_code', 'address', 'currencies', 'label', 'priority', 'is_enabled', 'last_leased_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'currencies' => 'array',
            'priority' => 'integer',
            'is_enabled' => 'boolean',
            'last_leased_at' => 'datetime',
        ];
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'network_code', 'code');
    }

    /** The lease record; absent until the address is handed out for the first time. */
    public function depositAddress(): HasOne
    {
        return $this->hasOne(DepositAddress::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return list<string> */
    public function currencyList(): array
    {
        return array_values(array_map('strval', (array) ($this->currencies ?? [])));
    }

    /** An empty list means "every currency enabled on this network". */
    public function accepts(string $currency): bool
    {
        $list = $this->currencyList();

        return $list === [] || in_array($currency, $list, true);
    }

    /**
     * Same-address comparison: EVM addresses are case-insensitive (EIP-55 is a
     * checksum on top of the same bytes), Tron base58 is exact.
     */
    public static function normalise(string $networkCode, string $address): string
    {
        $address = trim($address);

        return NetworkCode::isEvmCode($networkCode) ? mb_strtolower($address) : $address;
    }
}
