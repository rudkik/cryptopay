<?php

namespace App\Models;

use App\Enums\NetworkCode;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per network: the account-level xpub deposit addresses are derived
 * from, plus the derivation counter (SPEC §3, §4).
 *
 * `xpub` is deliberately *not* in `$fillable` — it is only ever written
 * through WalletService, which is the single place that audits the change and
 * makes sure the value never reaches a response or a log in full.
 */
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory, HasUuids;

    /** Default account-level branch per network family (SPEC §3). */
    public const EVM_PATH = "m/44'/60'/0'/0";

    public const TRON_PATH = "m/44'/195'/0'/0";

    protected $fillable = ['network_code', 'next_index', 'derivation_path', 'label'];

    /**
     * Belt and braces on top of `$fillable`: even a `toArray()` on the model
     * (a resource that forgets to pick fields, a `dd()` in a log) must not
     * emit the extended public key.
     */
    protected $hidden = ['xpub'];

    protected function casts(): array
    {
        return [
            'next_index' => 'integer',
            'xpub_set_at' => 'datetime',
        ];
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'xpub_set_by');
    }

    /** The default derivation path for a network, used when a row is created. */
    public static function defaultPathFor(string $networkCode): string
    {
        return NetworkCode::isEvmCode($networkCode) ? self::EVM_PATH : self::TRON_PATH;
    }

    public function hasXpub(): bool
    {
        return is_string($this->xpub) && $this->xpub !== '';
    }

    /**
     * The only form of the xpub that ever leaves this service: enough for an
     * operator to recognise which key is configured, not enough to derive the
     * address set from.
     */
    public function maskedXpub(): ?string
    {
        return $this->hasXpub() ? self::mask((string) $this->xpub) : null;
    }

    public static function mask(string $xpub): string
    {
        return mb_strlen($xpub) <= 16
            ? str_repeat('•', mb_strlen($xpub))
            : mb_substr($xpub, 0, 10).'…'.mb_substr($xpub, -6);
    }
}
