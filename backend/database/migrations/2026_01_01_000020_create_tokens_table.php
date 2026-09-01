<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_usd', 36, 18);
            $table->unsignedInteger('decimals')->default(18);
            $table->decimal('total_supply', 36, 18)->nullable();
            $table->decimal('sold', 36, 18)->default(0);
            $table->decimal('min_purchase', 36, 18)->default(0);
            $table->decimal('max_purchase', 36, 18)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('image_url')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens');
    }
};
