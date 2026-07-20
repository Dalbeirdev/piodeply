<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-level dunning state. Stripe retries the card on its own; these
 * fields pace OUR communication with the client while it does, and record
 * when WE suspended for non-payment — so a payment can safely auto-restore
 * exactly what billing suspended, and never a manual suspension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('subscription_past_due_since')->nullable()->after('subscription_period_end');
            $table->unsignedTinyInteger('dunning_stage')->default(0)->after('subscription_past_due_since');
            $table->timestamp('dunning_last_sent_at')->nullable()->after('dunning_stage');
            $table->timestamp('billing_suspended_at')->nullable()->after('dunning_last_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_past_due_since', 'dunning_stage',
                'dunning_last_sent_at', 'billing_suspended_at',
            ]);
        });
    }
};
