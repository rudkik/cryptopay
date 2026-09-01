<?php

namespace App\Models;

use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['network_code', 'next_index'];

    protected function casts(): array
    {
        return ['next_index' => 'integer'];
    }
}
