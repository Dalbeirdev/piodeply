<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Policy templates: a named bundle of software-policy definitions applied
 * to a project in one click. Items reference packages by winget id — the
 * portable identity — not by package row, so a template survives catalogue
 * differences and can even create the missing package on apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            // Built-ins ship with the product (seeded, refreshed on deploy);
            // the rest are operator-made snapshots.
            $table->boolean('is_builtin')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('policy_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_template_id')->constrained()->cascadeOnDelete();
            $table->string('winget_id', 190);
            $table->string('package_name', 190);
            $table->string('action', 20);
            $table->string('mode', 20)->default('enforce');
            $table->string('version_mode', 20)->default('latest');
            $table->string('frequency', 20)->default('weekly');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_template_items');
        Schema::dropIfExists('policy_templates');
    }
};
