<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployment_jobs', function (Blueprint $table) {
            // What the inventory reported at queue time, so the job can show
            // "138.0 -> 141.0" rather than a bare target. Null when nothing
            // was installed, or for binary packages whose version we cannot
            // read (see InstalledStateService).
            $table->string('installed_version_before', 100)
                ->nullable()
                ->after('target_version');
        });
    }

    public function down(): void
    {
        Schema::table('deployment_jobs', function (Blueprint $table) {
            $table->dropColumn('installed_version_before');
        });
    }
};
