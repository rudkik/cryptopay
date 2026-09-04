<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A merchant may now create an invoice without naming a currency and network:
 * the payer picks them on the hosted checkout, and only then is a deposit
 * address allocated (SPEC §6.1 / §6.3). Until that happens the three columns
 * are legitimately empty, so the NOT NULL constraints have to go.
 *
 * Only the nullability changes — the existing indexes and the foreign key on
 * `deposit_address_id` are left exactly as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('currency', 16)->nullable()->change();
            $table->string('network_code')->nullable()->change();
            $table->uuid('deposit_address_id')->nullable()->change();
        });

        // A token purchase quotes its invoice's currency, so it is unset for
        // exactly as long as the invoice is.
        Schema::table('token_purchases', function (Blueprint $table) {
            $table->string('currency', 16)->nullable()->change();
        });
    }

    /**
     * Reverting only works while no unselected invoice exists; rows created
     * through the new flow would violate the restored NOT NULL constraints.
     *
     * That is checked up front rather than left to Postgres, which would
     * otherwise raise an opaque 23502 *after* the first couple of columns had
     * already been altered — a half-reverted table plus an error message that
     * says nothing about why.
     */
    public function down(): void
    {
        $unselected = DB::table('invoices')
            ->whereNull('currency')
            ->orWhereNull('network_code')
            ->orWhereNull('deposit_address_id')
            ->count();

        if ($unselected > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$unselected} invoice(s) have no currency/network/deposit address yet "
                .'(SPEC §6.3 deferred selection). Restoring NOT NULL would reject them. '
                .'Cancel or delete those invoices first, then roll back.'
            );
        }

        Schema::table('token_purchases', function (Blueprint $table) {
            $table->string('currency', 16)->nullable(false)->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('currency', 16)->nullable(false)->change();
            $table->string('network_code')->nullable(false)->change();
            $table->uuid('deposit_address_id')->nullable(false)->change();
        });
    }
};
