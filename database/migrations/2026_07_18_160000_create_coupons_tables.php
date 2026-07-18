<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The coupon system: categories (for organising the admin list), the coupons
 * themselves, and a redemption log that powers the usage limits and analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();               // customer-typed, matched case-insensitively
            $table->string('name');
            $table->text('description')->nullable();

            // percent (value = 1..100) | fixed (value = cents) | trial_days (value = days)
            $table->string('type', 20);
            $table->unsignedInteger('value');
            $table->char('currency', 3)->default('usd');    // for fixed-amount

            // Stripe durations: once (one-time) | repeating | forever (lifetime)
            $table->string('duration', 20)->default('once');
            $table->unsignedInteger('duration_in_months')->nullable();

            // Restrictions / limits.
            $table->foreignId('plan_id')->nullable()->index();  // plan-specific (no DB FK: plans is a later migration on fresh installs)
            $table->timestamp('redeem_by')->nullable();          // expiration
            $table->unsignedInteger('max_redemptions')->nullable();   // global usage cap
            $table->unsignedInteger('max_per_customer')->nullable();  // per-account cap

            $table->boolean('auto_apply')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->string('stripe_coupon_id')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->timestamps();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->index();
            $table->unsignedInteger('amount_discounted_cents')->nullable();
            $table->timestamp('redeemed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('coupon_categories');
    }
};
