<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device groups: a named, hand-curated set of computers that cuts across
 * clients and projects (finance machines, kiosks, pilot ring of a rollout…).
 * Introduced for browser-policy scoping, but deliberately generic — a
 * "device tag" is simply membership of a group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('computer_computer_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['computer_id', 'computer_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_computer_group');
        Schema::dropIfExists('computer_groups');
    }
};
