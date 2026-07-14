<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            // Pinned target version for install/update; null = latest at run time.
            $table->foreignId('package_version_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedTinyInteger('priority')->default(5); // 1 = highest, 10 = lowest

            // Optional gate: this job stays blocked until the dependency succeeds.
            $table->foreignId('depends_on_job_id')->nullable()->constrained('deployment_jobs')->nullOnDelete();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('output_log')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['computer_id', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_jobs');
    }
};
