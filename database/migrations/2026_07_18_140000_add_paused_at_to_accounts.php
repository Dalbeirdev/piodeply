<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe's pause_collection is not mirrored into Cashier's local columns, so
 * we track "paused" ourselves: set when collection is paused, cleared when
 * resumed. Status derivation reads this alongside the subscription state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('grace_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
