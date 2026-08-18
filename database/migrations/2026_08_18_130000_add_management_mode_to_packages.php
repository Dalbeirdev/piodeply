<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "active/inactive" answered one question — can a job be queued — but
 * conflated two different reasons a package might not be: the catalogue is
 * wrong (deactivate), or the software genuinely cannot be handled this way
 * (Edge, Teams). Deactivating Edge made it disappear rather than explain
 * itself. This column separates the two.
 *
 * Every existing row defaults to 'deploy', so nothing behaves differently
 * until specific packages are reclassified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('management_mode', 20)->default('deploy')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('management_mode');
        });
    }
};
