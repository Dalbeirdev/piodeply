<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A self-service signup from the pricing page, held for review. Nothing
 * (no Client, no User) is created until an admin verifies the payment and
 * approves — a signup is an application, not an account. The password is
 * captured hashed at step one so approval never needs to ask the customer
 * anything; the plaintext never exists server-side beyond the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signups', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 150);
            $table->string('contact_name', 120);
            $table->string('email', 190);
            $table->string('password_hash');
            $table->string('phone', 40)->nullable();
            $table->string('country', 80)->nullable();
            $table->unsignedInteger('machines');
            $table->unsignedInteger('monthly_cents');
            $table->string('currency', 3)->default('usd');
            // pending_payment -> paid (webhook) -> approved / rejected.
            // awaiting_verification = no online payment (Stripe off or the
            // customer chose invoice) — the admin verifies out of band.
            $table->string('status', 30)->default('pending_payment')->index();
            $table->string('stripe_session_id')->nullable()->index();
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signups');
    }
};
