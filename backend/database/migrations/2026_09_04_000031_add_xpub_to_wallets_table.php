<?php

use App\Enums\NetworkCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The deposit wallet becomes an operator-editable row (SPEC §3): the xpub every
 * address for a network is derived from now lives in the database, with
 * `EVM_XPUB` / `TRON_XPUB` on the watcher kept only as a fallback.
 *
 * Only the *public* extended key is stored — the seed never reaches this
 * service — but it is still linkable to every deposit address, so it is never
 * returned in full by the API and never written to a log or the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->text('xpub')->nullable()->after('network_code');
            // The account-level branch the watcher derives `0/{index}` under.
            $table->string('derivation_path')->default("m/44'/60'/0'/0")->after('xpub');
            $table->string('label')->nullable()->after('derivation_path');
            $table->timestamp('xpub_set_at')->nullable()->after('next_index');
            $table->foreignId('xpub_set_by')->nullable()->after('xpub_set_at')
                ->constrained('users')->nullOnDelete();
        });

        // Existing rows predate the column, so give Tron its own coin type
        // rather than leaving it on the EVM default.
        DB::table('wallets')
            ->where('network_code', NetworkCode::Tron->value)
            ->update(['derivation_path' => "m/44'/195'/0'/0"]);
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('xpub_set_by');
            $table->dropColumn(['xpub', 'derivation_path', 'label', 'xpub_set_at']);
        });
    }
};
