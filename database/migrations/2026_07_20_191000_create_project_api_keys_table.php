<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project used to have exactly one API key, so "rotate" invalidated the
 * whole fleet's credential at once — rotating during a rollout stopped
 * every enrolled agent. Keys are now rows: create as many as needed (one
 * per site, per RMM, per rollout wave), revoke each on its own, and no key
 * operation ever affects machines using a different key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('key_hash', 64)->unique();
            $table->string('key_prefix', 12);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Every existing project's single key becomes its first key row, so
        // nothing already enrolled skips a beat. The legacy columns stay on
        // projects (dropping them is a later cleanup once nothing reads them).
        foreach (DB::table('projects')->whereNotNull('api_key_hash')->get() as $project) {
            DB::table('project_api_keys')->insert([
                'project_id' => $project->id,
                'label'      => 'Primary key',
                'key_hash'   => $project->api_key_hash,
                'key_prefix' => $project->api_key_prefix ?? '',
                'created_at' => $project->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_api_keys');
    }
};
