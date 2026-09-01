<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Token;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Token>
 */
class TokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'symbol' => 'TST',
            'name' => 'Test Token',
            'description' => null,
            'price_usd' => '0.25',
            'decimals' => 18,
            'total_supply' => '1000000',
            'sold' => '0',
            'min_purchase' => '1',
            'max_purchase' => '100000',
            'is_active' => true,
            'image_url' => null,
        ];
    }
}
