<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles gain a software dimension alongside where/what: WHICH packages
     * the holder may deploy — everything, chosen categories, or an explicit
     * package list.
     */
    public function up(): void
    {
        Schema::table('client_roles', function (Blueprint $table) {
            $table->string('package_scope', 12)->default('all')->after('scope');
        });

        Schema::create('client_role_package', function (Blueprint $table) {
            $table->foreignId('client_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();

            $table->primary(['client_role_id', 'package_id']);
        });

        Schema::create('client_role_package_category', function (Blueprint $table) {
            $table->foreignId('client_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_category_id')->constrained()->cascadeOnDelete();

            $table->primary(['client_role_id', 'package_category_id'], 'client_role_package_category_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_role_package_category');
        Schema::dropIfExists('client_role_package');

        Schema::table('client_roles', function (Blueprint $table) {
            $table->dropColumn('package_scope');
        });
    }
};
