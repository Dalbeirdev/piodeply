<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The MSP account — the billing tenant. There is one per install; it is the
 * Cashier "customer" (Billable), so the Cashier columns live here rather than
 * on `users`. A plan's device limit caps the total Computers across the
 * account's projects (enforced in Phase 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('My Company');

            // Cashier customer columns (normally added to `users`).
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            // Billing state we own. Plain indexed column (no DB-level FK): the
            // plans table is created by a later migration, and Cashier's own
            // tables likewise avoid cross-table constraints.
            $table->foreignId('plan_id')->nullable()->index();
            $table->string('billing_interval')->nullable();          // month | year
            // none | trialing | active | past_due | grace | suspended | canceled
            $table->string('status', 20)->default('none')->index();
            // Denormalised ceiling from the plan; an admin may override it
            // (Module 11). Null until a plan is chosen.
            $table->unsignedInteger('device_limit')->nullable();
            $table->boolean('device_limit_overridden')->default(false);
            // When a failed renewal's grace period ends and the account suspends.
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('trial_reminder_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
