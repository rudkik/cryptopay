<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('currency', 16);
            $table->string('network_code');
            $table->decimal('available', 36, 18)->default(0);
            $table->decimal('pending', 36, 18)->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'currency', 'network_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balances');
    }
};
