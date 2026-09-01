<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('event');
            $table->string('url');
            $table->json('payload');
            $table->string('signature')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(6);
            $table->string('status')->default('pending');
            $table->unsignedInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
