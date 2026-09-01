<?php

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id', 'name', 'key_prefix', 'key_hash', 'last_used_at', 'revoked_at',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
