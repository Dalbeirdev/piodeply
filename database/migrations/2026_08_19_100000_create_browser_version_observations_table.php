<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * computer_software is a full delete-and-reinsert on every agent report —
 * created_at/updated_at both stamp the report time regardless of whether
 * anything changed, so it cannot answer "how long has this machine been on
 * this version". That question is exactly what tells a working updater
 * apart from a disabled one, so it gets its own small, append-only table
 * rather than trying to retrofit history onto a table designed to have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_version_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->string('browser', 20); // App\Enums\Browser value
            $table->string('version', 100);
            // Nullable rather than a hard default: both are always set by
            // BrowserVersionService before a row is ever saved, and MySQL
            // strict mode refuses a bare TIMESTAMP with no default at all.
            // First report where THIS version was seen on THIS machine — the
            // clock a "stuck" browser is measured against.
            $table->timestamp('first_seen_at')->nullable();
            // Bumped on every report that still finds this version installed;
            // once it stops moving, this version is no longer current.
            $table->timestamp('last_seen_at')->nullable();
            $table->unique(['computer_id', 'browser', 'version'], 'bvo_computer_browser_version_unique');
            $table->index(['computer_id', 'browser', 'last_seen_at'], 'bvo_computer_browser_seen_index');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_version_observations');
    }
};
