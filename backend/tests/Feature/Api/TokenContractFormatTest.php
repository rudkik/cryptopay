<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenContractFormatTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();

        return User::query()->where('role', 'admin')->firstOrFail();
    }

    public function test_evm_contract_address_must_be_hex(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/networks/ethereum/tokens/USDT', ['contract_address' => '0xNOTANADDRESS'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonValidationErrorFor('contract_address', 'error.details');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/networks/ethereum/tokens/USDT', ['contract_address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7'])
            ->assertOk();
    }

    public function test_tron_contract_address_must_be_base58(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/networks/tron/tokens/USDT', ['contract_address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7'])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/networks/tron/tokens/USDT', ['contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'])
            ->assertOk();
    }
}
