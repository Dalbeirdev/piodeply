<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-client branding: what the portal sidebar shows THEIR users, what
     * the tray tooltip on THEIR machines says, and whether the tray icon
     * appears on their machines at all. Null names fall back to the
     * platform defaults.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('portal_name', 60)->nullable()->after('company_name');
            $table->string('tray_name', 60)->nullable()->after('portal_name');
            $table->boolean('show_tray_icon')->default(true)->after('tray_name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['portal_name', 'tray_name', 'show_tray_icon']);
        });
    }
};
