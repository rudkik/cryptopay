<?php

namespace Database\Seeders;

use App\Models\Network;
use App\Models\TokenContract;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

/**
 * Networks, mainnet token contracts and HD wallet counters from SPEC §2.
 *
 * Idempotent: re-running only fills in missing rows and never overwrites
 * operator edits made through the admin API (confirmations, enable flags,
 * contract addresses) or the watcher's scan progress.
 */
class NetworkSeeder extends Seeder
{
    public function run(): void
    {
        $networks = [
            [
                'code' => 'ethereum',
                'name' => 'Ethereum',
                'chain_id' => 1,
                'confirmations_required' => 12,
                'explorer_tx_url' => 'https://etherscan.io/tx/{hash}',
                'explorer_address_url' => 'https://etherscan.io/address/{address}',
            ],
            [
                'code' => 'bsc',
                'name' => 'BNB Smart Chain',
                'chain_id' => 56,
                'confirmations_required' => 15,
                'explorer_tx_url' => 'https://bscscan.com/tx/{hash}',
                'explorer_address_url' => 'https://bscscan.com/address/{address}',
            ],
            [
                'code' => 'tron',
                'name' => 'Tron',
                'chain_id' => null,
                'confirmations_required' => 19,
                'explorer_tx_url' => 'https://tronscan.org/#/transaction/{hash}',
                'explorer_address_url' => 'https://tronscan.org/#/address/{address}',
            ],
        ];

        foreach ($networks as $attributes) {
            Network::query()->firstOrCreate(
                ['code' => $attributes['code']],
                $attributes + ['is_enabled' => true, 'watcher_healthy' => false],
            );

            Wallet::query()->firstOrCreate(
                ['network_code' => $attributes['code']],
                [
                    'next_index' => 0,
                    'derivation_path' => Wallet::defaultPathFor($attributes['code']),
                ],
            );
        }

        $contracts = [
            ['ethereum', 'USDT', '0xdAC17F958D2ee523a2206206994597C13D831ec7', 6],
            ['ethereum', 'USDC', '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 6],
            ['bsc', 'USDT', '0x55d398326f99059fF775485246999027B3197955', 18],
            ['bsc', 'USDC', '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 18],
            ['tron', 'USDT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 6],
            ['tron', 'USDC', 'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8', 6],
        ];

        foreach ($contracts as [$network, $symbol, $address, $decimals]) {
            TokenContract::query()->firstOrCreate(
                ['network_code' => $network, 'symbol' => $symbol],
                ['contract_address' => $address, 'decimals' => $decimals, 'is_enabled' => true],
            );
        }
    }
}
