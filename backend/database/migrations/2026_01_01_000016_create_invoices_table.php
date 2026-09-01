<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('type')->default('payment');
            $table->string('external_id')->nullable();
            $table->string('currency', 16);
            $table->string('network_code');
            $table->foreignUuid('deposit_address_id')->constrained('deposit_addresses')->restrictOnDelete();
            $table->decimal('amount', 36, 18);
            $table->decimal('amount_received', 36, 18)->default(0);
            $table->decimal('amount_confirmed', 36, 18)->default(0);
            $table->string('status')->default('pending');
            $table->text('description')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('success_url')->nullable();
            $table->string('cancel_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'external_id']);
            $table->index('status');
            $table->index('expires_at');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
