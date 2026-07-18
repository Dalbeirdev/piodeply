<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Stripe reference (checkout session / invoice id) identifies one payment.
 * Making it unique stops a redelivered webhook from inserting a second row and
 * double-counting revenue. (Null references — legacy manual rows — are exempt:
 * SQL treats NULLs as distinct.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['reference']);
        });
    }
};
