<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            // The agent's own readiness self-checks — winget usable as SYSTEM,
            // required runtimes present. A machine that fails one cannot sync
            // or deploy software reliably, and we would rather say so than let
            // an operator discover it one failed job at a time.
            $table->json('environment')->nullable()->after('agent_version');
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn('environment');
        });
    }
};
