<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project technician assignment. No rows = the user roams their whole
 * tenant (today's behaviour, and what owners keep); one or more rows =
 * the user is CONFINED to those projects, everywhere — lists, pages,
 * deploy targets. Assigning is how an owner restricts, not how they
 * grant: the default stays open so small teams need no ceremony.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
    }
};
