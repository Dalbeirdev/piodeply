<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An operator's acknowledgement of a failure cause on the "Needs attention"
 * queue — "I know, I've dealt with it." Tied to the specific failing job seen
 * at dismissal time (last_seen_job_id), so a FRESH failure of the same cause
 * reappears rather than staying silenced forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_failure_dismissals', function (Blueprint $table) {
            $table->id();
            // Matches DeploymentJob::causeKey() exactly, e.g.
            // "package:14:-1978335212" or "machine:7:112".
            $table->string('cause_key')->unique();
            $table->unsignedBigInteger('last_seen_job_id');
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dismissed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_failure_dismissals');
    }
};
