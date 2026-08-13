<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The approval workflow: a member whose custom role requires approval
     * REQUESTS a deployment; the account owner approves (which queues the
     * real job) or rejects. Requests are the audit trail either way.
     */
    public function up(): void
    {
        Schema::create('deployment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20);
            $table->string('target_version', 100)->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('job_id')->nullable()->constrained('deployment_jobs')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::table('client_roles', function (Blueprint $table) {
            // Holders of this role never deploy directly: their actions
            // become requests the account owner approves.
            $table->boolean('requires_approval')->default(false)->after('can_uninstall');
        });
    }

    public function down(): void
    {
        Schema::table('client_roles', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });
        Schema::dropIfExists('deployment_requests');
    }
};
