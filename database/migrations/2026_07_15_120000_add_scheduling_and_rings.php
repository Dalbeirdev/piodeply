<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->string('ring', 12)->default('production')->after('project_id');
        });

        Schema::table('software_policies', function (Blueprint $table) {
            $table->string('frequency', 10)->default('daily')->after('priority');
            // Maintenance window: null days = anytime ("immediately").
            $table->json('window_days')->nullable()->after('frequency');       // [1..7] ISO weekdays
            $table->time('window_start')->nullable()->after('window_days');
            $table->time('window_end')->nullable()->after('window_start');
            // Staged rollout, in days after rollout_started_at.
            $table->unsignedSmallInteger('test_delay_days')->default(0)->after('window_end');
            $table->unsignedSmallInteger('production_delay_days')->default(0)->after('test_delay_days');
            $table->timestamp('rollout_started_at')->nullable()->after('production_delay_days');
        });

        DB::table('software_policies')->update(['rollout_started_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('software_policies', function (Blueprint $table) {
            $table->dropColumn([
                'frequency', 'window_days', 'window_start', 'window_end',
                'test_delay_days', 'production_delay_days', 'rollout_started_at',
            ]);
        });
        Schema::table('computers', fn (Blueprint $table) => $table->dropColumn('ring'));
    }
};
