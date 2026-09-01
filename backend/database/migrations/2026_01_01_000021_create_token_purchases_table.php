<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('token_id')->constrained('tokens')->cascadeOnDelete();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('customer_id');
            $table->string('customer_email')->nullable();
            $table->decimal('token_amount', 36, 18);
            $table->decimal('price_usd', 36, 18);
            $table->decimal('pay_amount', 36, 18);
            $table->string('currency', 16);
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['token_id', 'customer_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_purchases');
    }
};
