<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            // Agent authentication: only a hash is stored; the plaintext key
            // is shown once at generation/rotation time.
            $table->string('api_key_hash', 64)->unique();
            $table->string('api_key_prefix', 16);
            $table->timestamp('api_key_rotated_at')->nullable();
            // Public agent-download URL token (route lands in a later phase).
            $table->string('download_token', 40)->unique();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['client_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
