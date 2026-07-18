<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate / referral programme: affiliates and their referral code, the
 * click log, accrued commissions (pending → approved → paid, or rejected),
 * and payout requests. `accounts.referred_by_affiliate_id` links a referred
 * install back to whoever sent it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index(); // optional self-serve login
            $table->string('name');
            $table->string('email');
            $table->string('code')->unique();                  // the ?ref= slug
            // percentage (rate = %) | fixed (rate = cents per conversion)
            $table->string('commission_type', 20)->default('percentage');
            $table->unsignedInteger('commission_rate')->default(20);
            $table->boolean('recurring')->default(true);        // pay on every invoice vs first only
            // pending | approved | rejected
            $table->string('status', 20)->default('pending')->index();
            $table->string('payout_method')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('landing_path')->nullable();
            $table->string('referer')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->index();
            $table->string('source_invoice')->nullable();       // Stripe invoice id (idempotency)
            $table->unsignedInteger('base_amount_cents')->default(0);
            $table->unsignedInteger('amount_cents')->default(0); // the commission earned
            // pending | approved | rejected | paid
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['affiliate_id', 'source_invoice']); // one commission per invoice
        });

        Schema::create('affiliate_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            // requested | paid | rejected
            $table->string('status', 20)->default('requested')->index();
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('referred_by_affiliate_id')->nullable()->after('paused_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('referred_by_affiliate_id');
        });
        Schema::dropIfExists('affiliate_withdrawals');
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliates');
    }
};
