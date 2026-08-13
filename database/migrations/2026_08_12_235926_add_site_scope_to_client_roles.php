<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles gain a third scope level: 'all' machines, whole 'sites'
     * (auto-covers machines that enrol later), or explicit 'computers'.
     * The scope column replaces the all_computers boolean.
     */
    public function up(): void
    {
        Schema::table('client_roles', function (Blueprint $table) {
            $table->string('scope', 10)->default('all')->after('can_uninstall');
        });

        DB::table('client_roles')->where('all_computers', false)->update(['scope' => 'computers']);

        Schema::table('client_roles', function (Blueprint $table) {
            $table->dropColumn('all_computers');
        });

        Schema::create('client_role_project', function (Blueprint $table) {
            $table->foreignId('client_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->primary(['client_role_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_role_project');

        Schema::table('client_roles', function (Blueprint $table) {
            $table->boolean('all_computers')->default(true);
        });

        DB::table('client_roles')->where('scope', 'computers')->update(['all_computers' => false]);

        Schema::table('client_roles', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
