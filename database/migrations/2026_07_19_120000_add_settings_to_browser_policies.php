<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Value-typed browser policies (forced homepage URL, force-installed
 * extension lists…) carry their payload here; toggle policies leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_policies', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('browser_policies', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
