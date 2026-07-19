<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the last scheduled dunning reminder went out. Stripe-driven failure
 * emails fire per retry; this timestamp paces the follow-ups we send after
 * Stripe has given up. Cleared when a payment succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('dunning_notified_at')->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('dunning_notified_at');
        });
    }
};
