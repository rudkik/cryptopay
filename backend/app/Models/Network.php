<?php

namespace App\Models;

use App\Enums\NetworkCode;
use Database\Factories\NetworkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Network extends Model
{
    /** @use HasFactory<NetworkFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'code', 'name', 'chain_id', 'confirmations_required', 'is_enabled',
        'explorer_tx_url', 'explorer_address_url', 'last_scanned_block',
        'watcher_healthy', 'watcher_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'confirmations_required' => 'integer',
            'is_enabled' => 'boolean',
            'last_scanned_block' => 'integer',
            'watcher_healthy' => 'boolean',
            'watcher_seen_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function tokenContracts(): HasMany
    {
        return $this->hasMany(TokenContract::class, 'network_code', 'code');
    }

    public function isEvm(): bool
    {
        return NetworkCode::isEvmCode($this->code);
    }

    public function explorerTxUrl(?string $hash): ?string
    {
        if (! $hash || ! $this->explorer_tx_url) {
            return null;
        }

        return str_replace('{hash}', $hash, $this->explorer_tx_url);
    }

    public function explorerAddressUrl(?string $address): ?string
    {
        if (! $address || ! $this->explorer_address_url) {
            return null;
        }

        return str_replace('{address}', $address, $this->explorer_address_url);
    }
}
