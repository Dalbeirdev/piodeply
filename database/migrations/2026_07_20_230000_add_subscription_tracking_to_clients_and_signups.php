<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signup wizard already creates a real recurring Stripe subscription —
 * Stripe charges it monthly on its own. What was missing is the app's view
 * of it: which client it belongs to, whether the last charge worked, and
 * when it renews. These columns are that view, kept current by webhooks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('stripe_customer_id', 64)->nullable()->after('billing_tax_id');
            $table->string('stripe_subscription_id', 64)->nullable()->index()->after('stripe_customer_id');
            // Stripe's own vocabulary: trialing / active / past_due /
            // canceled / unpaid / incomplete. Null = never subscribed.
            $table->string('subscription_status', 30)->nullable()->after('stripe_subscription_id');
            $table->unsignedInteger('subscription_machines')->nullable()->after('subscription_status');
            $table->unsignedInteger('subscription_cents')->nullable()->after('subscription_machines');
            $table->timestamp('subscription_period_end')->nullable()->after('subscription_cents');
        });

        Schema::table('signups', function (Blueprint $table) {
            $table->string('stripe_customer_id', 64)->nullable()->after('stripe_session_id');
            $table->string('stripe_subscription_id', 64)->nullable()->index()->after('stripe_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_customer_id', 'stripe_subscription_id', 'subscription_status',
                'subscription_machines', 'subscription_cents', 'subscription_period_end',
            ]);
        });

        Schema::table('signups', function (Blueprint $table) {
            $table->dropColumn(['stripe_customer_id', 'stripe_subscription_id']);
        });
    }
};
