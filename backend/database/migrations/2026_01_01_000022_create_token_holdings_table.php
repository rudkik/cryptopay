<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_holdings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('token_id')->constrained('tokens')->cascadeOnDelete();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('customer_id');
            $table->decimal('amount', 36, 18)->default(0);
            $table->timestamps();

            $table->unique(['token_id', 'customer_id']);
            $table->index(['merchant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_holdings');
    }
};
