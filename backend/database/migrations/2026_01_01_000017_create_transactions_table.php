<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('deposit_address_id')->constrained('deposit_addresses')->cascadeOnDelete();
            $table->foreignUuid('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->string('network_code');
            $table->string('tx_hash');
            $table->unsignedInteger('log_index')->default(0);
            $table->string('from_address')->nullable();
            $table->string('to_address');
            $table->string('currency', 16);
            $table->string('contract_address')->nullable();
            $table->decimal('amount', 36, 18);
            $table->string('amount_raw')->nullable();
            $table->bigInteger('block_number')->nullable();
            $table->string('block_hash')->nullable();
            $table->unsignedInteger('confirmations')->default(0);
            $table->string('status')->default('detected');
            $table->timestamp('credited_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['network_code', 'tx_hash', 'log_index']);
            $table->index('status');
            $table->index(['merchant_id', 'currency', 'network_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
