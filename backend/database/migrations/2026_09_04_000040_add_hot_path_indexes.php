<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries that run on every ingested transfer, every watcher
 * poll and every list page. Measured on a 300k-row copy of the schema before
 * and after (Postgres 16, EXPLAIN ANALYZE): each of these was a sequential or
 * near-sequential scan.
 *
 * Nothing here changes behaviour — only how much of the table each query has to
 * read.
 */
return new class extends Migration
{
    /**
     * AddressService::findByAddress() compares EVM addresses case-insensitively
     * (EIP-55 checksums vary by source), so it queries `lower(address)` and the
     * plain (network_code, address) unique index cannot serve it. This is the
     * lookup that decides whether an incoming transfer is ours, once per
     * transfer, so it is the single most valuable index in the schema.
     *
     * A functional index has no Blueprint equivalent; the SQL below is valid on
     * both Postgres and SQLite (expression indexes, 3.9+).
     */
    private const LOWER_ADDRESS_INDEX = 'deposit_addresses_network_code_lower_address_index';

    public function up(): void
    {
        DB::statement('CREATE INDEX '.self::LOWER_ADDRESS_INDEX
            .' ON deposit_addresses (network_code, lower(address))');

        Schema::table('deposit_addresses', function (Blueprint $table) {
            // GET /api/internal/watch-addresses, polled by the watcher every
            // 10s per network with `updated_since`; derivation_index is the
            // ORDER BY, which otherwise spilled the full listing to disk.
            $table->index(
                ['network_code', 'is_active', 'updated_at', 'derivation_index'],
                'deposit_addresses_watch_index',
            );
        });

        Schema::table('invoices', function (Blueprint $table) {
            // GET /api/v1/invoices and GET /api/admin/invoices: filter by
            // merchant and status, order by created_at desc.
            $table->index(['merchant_id', 'status', 'created_at'], 'invoices_merchant_status_created_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            // GET /api/v1/transactions and the admin twin.
            $table->index(['network_code', 'status', 'created_at'], 'transactions_network_status_created_index');

            // TransactionIngestService::syncPending() re-sums the merchant's
            // still-unconfirmed transfers on every ingest. `status` was missing
            // from the existing composite, so every matching row was fetched
            // from the heap only to be discarded.
            $table->index(
                ['merchant_id', 'currency', 'network_code', 'status'],
                'transactions_pending_sum_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_pending_sum_index');
            $table->dropIndex('transactions_network_status_created_index');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_merchant_status_created_index');
        });

        Schema::table('deposit_addresses', function (Blueprint $table) {
            $table->dropIndex('deposit_addresses_watch_index');
        });

        DB::statement('DROP INDEX IF EXISTS '.self::LOWER_ADDRESS_INDEX);
    }
};
