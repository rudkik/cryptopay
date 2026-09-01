<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('currency', 16);
            $table->string('network_code');
            $table->decimal('amount', 36, 18);
            $table->string('type')->default('deposit');
            $table->foreignUuid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->decimal('balance_after', 36, 18)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'currency', 'network_code']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
