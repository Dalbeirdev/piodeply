<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing foundation (Phase 1): the fixed subscription plans, and the
 * enterprise-quote inbox for fleets that outgrow the largest plan.
 *
 * Prices are stored in minor units (cents) as integers — never floats — so
 * money math is exact. Stripe price IDs are nullable here; Phase 2 populates
 * them once the products are created in Stripe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('device_limit');            // machines this plan allows
            $table->unsignedInteger('monthly_price_cents');
            $table->unsignedInteger('yearly_price_cents');
            $table->char('currency', 3)->default('usd');
            $table->json('features')->nullable();               // bullet list shown on the card
            $table->boolean('is_recommended')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            // Populated in Phase 2 when the Stripe products/prices are created.
            $table->string('stripe_monthly_price_id')->nullable();
            $table->string('stripe_yearly_price_id')->nullable();
            $table->timestamps();
        });

        Schema::create('enterprise_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->unsignedInteger('device_count');
            $table->string('current_rmm')->nullable();
            $table->string('expected_growth')->nullable();
            $table->text('notes')->nullable();
            // new -> contacted -> won / lost. Plain string, validated in code,
            // so adding a stage never needs a migration.
            $table->string('status', 20)->default('new')->index();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        // Internal thread on a quote (admin notes / correspondence log).
        Schema::create('quote_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_quote_id')->constrained()->cascadeOnDelete();
            $table->string('author');                           // 'system', an admin name, etc.
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_messages');
        Schema::dropIfExists('enterprise_quotes');
        Schema::dropIfExists('plans');
    }
};
