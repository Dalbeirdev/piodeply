<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployment_jobs', function (Blueprint $table) {
            // What the agent found installed once the job finished. Turns the
            // job's intent ("-> latest") into what actually happened. Null for
            // agents older than 1.3.0, and for packages whose version cannot
            // be read.
            $table->string('installed_version_after', 100)
                ->nullable()
                ->after('installed_version_before');
        });
    }

    public function down(): void
    {
        Schema::table('deployment_jobs', function (Blueprint $table) {
            $table->dropColumn('installed_version_after');
        });
    }
};
