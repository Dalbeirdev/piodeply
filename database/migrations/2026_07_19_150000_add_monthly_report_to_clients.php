<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in flag: when set, the client's portal users receive the monthly
 * compliance report PDF by email. Off by default so enabling the feature
 * changes nothing until a client is explicitly opted in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('monthly_report')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('monthly_report');
        });
    }
};
