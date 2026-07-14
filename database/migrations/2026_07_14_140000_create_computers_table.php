<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Agent identity & lifecycle
            $table->uuid('agent_uuid')->unique();
            $table->string('agent_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();

            // Identity
            $table->string('hostname')->index();
            $table->string('serial_number')->nullable()->index();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();

            // OS
            $table->string('os_name')->nullable();
            $table->string('os_version', 100)->nullable();
            $table->string('windows_build', 50)->nullable();

            // Hardware
            $table->string('cpu')->nullable();
            $table->unsignedBigInteger('ram_bytes')->nullable();
            $table->unsignedBigInteger('disk_total_bytes')->nullable();
            $table->unsignedBigInteger('disk_free_bytes')->nullable();

            // Network
            $table->string('public_ip', 45)->nullable();
            $table->string('private_ip', 45)->nullable();
            $table->string('mac_address', 17)->nullable();

            // Security posture
            $table->boolean('secure_boot')->nullable();
            $table->boolean('tpm_enabled')->nullable();
            $table->string('tpm_version', 20)->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computers');
    }
};
