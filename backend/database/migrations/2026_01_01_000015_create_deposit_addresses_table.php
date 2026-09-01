<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('network_code');
            $table->string('address');
            $table->unsignedInteger('derivation_index');
            $table->foreignUuid('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            // invoice_id is intentionally left without a FK constraint: invoices
            // reference deposit_addresses, so a FK back would be circular.
            $table->uuid('invoice_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['network_code', 'address']);
            $table->index(['network_code', 'is_active']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_addresses');
    }
};
