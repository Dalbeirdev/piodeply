<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom (admin-saved) browser-policy templates. The seven built-in
 * templates live in code (BrowserPolicyTemplateService::builtins), so they
 * need no seeding and update with the catalogue automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_policy_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description', 500)->nullable();
            // [{type, action}] — browsers stay 'all'; the per-policy rows an
            // apply creates can be edited individually afterwards.
            $table->json('policies');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_policy_templates');
    }
};
