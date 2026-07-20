<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot commands an operator can queue for a machine's agent, delivered
 * on its next heartbeat: reinstall (re-download the current bundle and swap,
 * whatever state the install is in) and uninstall (remove the agent from the
 * machine entirely). Timestamps, not booleans, so the UI can say when it was
 * asked for and how stale the request is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->timestamp('reinstall_requested_at')->nullable()->after('agent_version');
            $table->timestamp('uninstall_requested_at')->nullable()->after('reinstall_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn(['reinstall_requested_at', 'uninstall_requested_at']);
        });
    }
};
