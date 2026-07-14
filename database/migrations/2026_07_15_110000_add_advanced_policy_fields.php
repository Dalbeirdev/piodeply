<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('software_policies', function (Blueprint $table) {
            $table->string('mode', 10)->default('enforce')->after('action');
            $table->string('version_mode', 10)->default('latest')->after('mode');
            $table->string('desired_version', 100)->nullable()->after('version_mode');
        });

        // is_active=false becomes the Disabled mode.
        DB::table('software_policies')->where('is_active', false)->update(['mode' => 'disabled']);

        Schema::table('software_policies', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });

        Schema::create('software_policy_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('software_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['software_policy_id', 'computer_id']);
        });

        Schema::table('deployment_jobs', function (Blueprint $table) {
            // Policy-pinned version (winget --version). Distinct from
            // package_version_id, which references catalogue binaries.
            $table->string('target_version', 100)->nullable()->after('package_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('deployment_jobs', fn (Blueprint $table) => $table->dropColumn('target_version'));
        Schema::dropIfExists('software_policy_exclusions');
        Schema::table('software_policies', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->dropColumn(['mode', 'version_mode', 'desired_version']);
        });
    }
};
