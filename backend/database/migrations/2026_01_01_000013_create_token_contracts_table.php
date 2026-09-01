<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('network_code');
            $table->string('symbol', 16);
            $table->string('contract_address');
            $table->unsignedInteger('decimals');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['network_code', 'symbol']);
            $table->foreign('network_code')->references('code')->on('networks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_contracts');
    }
};
