<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('networks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('chain_id')->nullable();
            $table->unsignedInteger('confirmations_required')->default(12);
            $table->boolean('is_enabled')->default(true);
            $table->string('explorer_tx_url')->nullable();
            $table->string('explorer_address_url')->nullable();
            $table->bigInteger('last_scanned_block')->nullable();
            $table->boolean('watcher_healthy')->default(false);
            $table->timestamp('watcher_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('networks');
    }
};
