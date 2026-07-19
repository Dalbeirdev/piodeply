<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assignment scopes for browser policies. scope_type says what kind of
 * thing the policy targets (all | client | project | group | computer) and
 * scope_id which one (0 for "all", so the composite unique works on MySQL,
 * where NULLs would not collide). Existing rows become project-scoped,
 * byte-for-byte equivalent to their old behaviour; project_id is kept (now
 * nullable) for the relation and template apply.
 *
 * Every step is guarded so a partially-applied earlier run (MySQL 1553: the
 * old unique index also backed the project_id FK, so dropping it needs a
 * plain replacement index first) can simply be re-run to completion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('browser_policies', 'scope_type')) {
            Schema::table('browser_policies', function (Blueprint $table) {
                $table->string('scope_type', 20)->default('project')->after('project_id');
                $table->unsignedBigInteger('scope_id')->default(0)->after('scope_type');
            });

            DB::table('browser_policies')->update(['scope_type' => 'project', 'scope_id' => DB::raw('project_id')]);
        }

        // MySQL uses the unique(project_id, type) index to back the
        // project_id foreign key; give the FK a plain index to lean on
        // BEFORE the unique goes away, or the drop fails with errno 1553.
        if (! Schema::hasIndex('browser_policies', 'browser_policies_project_id_index')) {
            Schema::table('browser_policies', function (Blueprint $table) {
                $table->index('project_id', 'browser_policies_project_id_index');
            });
        }

        if (Schema::hasIndex('browser_policies', 'browser_policies_project_id_type_unique')) {
            Schema::table('browser_policies', function (Blueprint $table) {
                $table->dropUnique(['project_id', 'type']);
            });
        }

        // Uniqueness follows the scope now: one rule per scope+type.
        if (! Schema::hasIndex('browser_policies', 'browser_policies_scope_type_scope_id_type_unique')) {
            Schema::table('browser_policies', function (Blueprint $table) {
                $table->unique(['scope_type', 'scope_id', 'type']);
            });
        }

        Schema::table('browser_policies', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('browser_policies', function (Blueprint $table) {
            $table->dropUnique(['scope_type', 'scope_id', 'type']);
            $table->dropColumn(['scope_type', 'scope_id']);
            $table->unique(['project_id', 'type']);
            $table->dropIndex('browser_policies_project_id_index');
        });
    }
};
