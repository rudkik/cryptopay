<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Static receiving addresses: an operator-maintained list of "pay here"
 * addresses, each bound to one network and to the currencies it may accept.
 * An invoice leases one of them for its lifetime instead of deriving a fresh
 * HD address (which stays available as the fallback when the list is empty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receiving_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('network_code');
            $table->string('address');
            // Empty list = every currency enabled on the network.
            $table->json('currencies')->nullable();
            $table->string('label', 100)->nullable();
            // Lower wins; equal priorities rotate by last_leased_at.
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_leased_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['network_code', 'address']);
            $table->index(['network_code', 'is_enabled', 'priority']);
        });

        Schema::table('deposit_addresses', function (Blueprint $table) {
            // A pooled address is not derived, so it has no index.
            $table->unsignedInteger('derivation_index')->nullable()->change();
            $table->foreignUuid('receiving_address_id')
                ->nullable()
                ->after('derivation_index')
                ->constrained('receiving_addresses')
                ->nullOnDelete();
            // While set and in the future, the address belongs to `invoice_id`
            // and cannot be handed to another invoice.
            $table->timestamp('leased_until')->nullable()->after('is_active');

            $table->index(['receiving_address_id', 'leased_until']);
        });
    }

    public function down(): void
    {
        Schema::table('deposit_addresses', function (Blueprint $table) {
            $table->dropIndex(['receiving_address_id', 'leased_until']);
            $table->dropConstrainedForeignId('receiving_address_id');
            $table->dropColumn('leased_until');
        });

        Schema::dropIfExists('receiving_addresses');
    }
};
