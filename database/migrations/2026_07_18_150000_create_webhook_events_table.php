<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every Stripe webhook we receive — the idempotency key (Stripe's
 * event id is unique), an audit log, and the queue the dashboard retries from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_id')->unique();   // evt_… — idempotency key
            $table->string('type')->index();         // e.g. invoice.payment_failed
            // received | processed | failed | skipped
            $table->string('status', 20)->default('received')->index();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
