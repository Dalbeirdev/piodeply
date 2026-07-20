<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package ownership. NULL = the shared catalogue every tenant can use; a
 * client id makes the package PRIVATE to that client — their uploaded
 * installers and licensed software, invisible to other tenants and
 * undeployable to anyone else's machines, staff included.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('package_category_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
