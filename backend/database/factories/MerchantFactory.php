<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Services\ApiKeyService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'email' => $this->faker->safeEmail(),
            'webhook_url' => null,
            'webhook_secret' => ApiKeyService::generateWebhookSecret(),
            'is_active' => true,
            'settings' => ['underpayment_tolerance' => 0.5],
        ];
    }
}
