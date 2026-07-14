<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_contents', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('stripe');
            $table->string('reference')->nullable();        // checkout session / payment intent id
            $table->string('customer_email')->nullable();
            $table->string('plan', 40)->nullable();
            $table->unsignedInteger('quantity')->nullable(); // endpoints
            $table->unsignedBigInteger('amount_total')->nullable(); // minor units
            $table->string('currency', 10)->nullable();
            $table->string('status', 20)->default('pending'); // pending | paid | failed
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('site_contents');
    }
};
