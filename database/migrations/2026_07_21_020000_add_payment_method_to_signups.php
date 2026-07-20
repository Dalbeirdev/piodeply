<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the applicant chose to pay: card (Stripe checkout, the default) or
 * invoice (big customers whose accounts department will not do cards).
 * Recorded so the admin queue can say "invoice requested" instead of the
 * ambiguous "verify manually", and reports can tell the paths apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signups', function (Blueprint $table) {
            $table->string('payment_method', 10)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('signups', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
