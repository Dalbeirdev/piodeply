<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Installer is machine-wide but the winget manifest declares no
            // scope, so forcing --scope machine finds no applicable installer
            // (0x8A150010) even though the payload lands in Program Files —
            // the .NET runtimes are the classic case. The agent omits the
            // scope argument when this is set.
            $table->boolean('winget_scopeless')->default(false)->after('winget_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('winget_scopeless');
        });
    }
};
