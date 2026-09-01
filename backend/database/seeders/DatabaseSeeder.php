<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Runs on every container start (`db:seed --force` from the entrypoint), so
 * every seeder below must be idempotent.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            NetworkSeeder::class,
            AdminUserSeeder::class,
            DemoMerchantSeeder::class,
        ]);
    }
}
