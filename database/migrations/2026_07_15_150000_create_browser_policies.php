<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);       // BrowserPolicyType
            $table->json('browsers');         // subset of Browser values ("all" = every case)
            $table->string('action', 10)->default('disable'); // disable = restrict, enable = explicitly allow
            $table->string('status', 10)->default('active');  // active | inactive
            $table->string('description', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One rule per project+type: two policies fighting over the same
            // registry value is a conflict, not a configuration.
            $table->unique(['project_id', 'type']);
        });

        Schema::create('browser_policy_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('browser_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['browser_policy_id', 'computer_id']);
        });

        Schema::create('browser_policy_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('browser_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->string('browser', 10);
            // compliant | pending_restart | non_compliant | unsupported | not_installed | error
            $table->string('status', 20);
            $table->string('detail', 500)->nullable();
            $table->string('old_value', 100)->nullable();
            $table->string('new_value', 100)->nullable();
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->unique(['browser_policy_id', 'computer_id', 'browser'], 'bp_results_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_policy_results');
        Schema::dropIfExists('browser_policy_exclusions');
        Schema::dropIfExists('browser_policies');
    }
};
