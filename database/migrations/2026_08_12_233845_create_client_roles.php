<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom client-side roles: an account owner defines what a person may
     * DO (install / update / uninstall) and WHERE (every machine, or an
     * explicit list). This is an overlay on top of the ladder role — the
     * user still carries a base ladder role for page access; the overlay
     * narrows deployment actions and machine visibility.
     */
    public function up(): void
    {
        Schema::create('client_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('description', 200)->nullable();
            $table->boolean('can_install')->default(false);
            $table->boolean('can_update')->default(true);
            $table->boolean('can_uninstall')->default(false);
            // true = every machine in the client's environment; false = only
            // the machines in client_role_computer.
            $table->boolean('all_computers')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'name']);
        });

        Schema::create('client_role_computer', function (Blueprint $table) {
            $table->foreignId('client_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();

            $table->primary(['client_role_id', 'computer_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('client_role_id')->nullable()
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_role_id');
        });
        Schema::dropIfExists('client_role_computer');
        Schema::dropIfExists('client_roles');
    }
};
