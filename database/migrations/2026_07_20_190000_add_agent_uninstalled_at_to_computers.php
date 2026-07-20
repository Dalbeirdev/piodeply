<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof the agent is gone, which is what permanent deletion requires.
 * Set when an uninstall command is delivered to the agent; cleared by any
 * later heartbeat or registration — a machine that still checks in visibly
 * did not uninstall, so the proof self-corrects rather than trusting a flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->timestamp('agent_uninstalled_at')->nullable()->after('uninstall_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn('agent_uninstalled_at');
        });
    }
};
