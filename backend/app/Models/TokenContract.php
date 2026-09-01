<?php

namespace App\Models;

use Database\Factories\TokenContractFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenContract extends Model
{
    /** @use HasFactory<TokenContractFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'network_code', 'symbol', 'contract_address', 'decimals', 'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'network_code', 'code');
    }
}
