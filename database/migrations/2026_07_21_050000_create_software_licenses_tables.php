<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid software licenses, always owned by one client. The key itself is
 * encrypted at rest and readable only by the owning tenant — staff see
 * that a license exists (support needs that) but never its value.
 * Assignments pin seats to computers so usage and over-allocation are
 * visible, and a seat count enforces the ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('vendor', 150)->nullable();
            $table->text('license_key_encrypted')->nullable();
            $table->string('key_last4', 8)->nullable(); // visible fingerprint, never the key
            $table->unsignedInteger('seats')->nullable(); // null = site/unlimited
            $table->date('expires_at')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('software_license_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('software_license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Named by hand: the auto-generated name is 67 chars and MySQL
            // caps identifiers at 64 (SQLite does not care — tests alone
            // would never catch this).
            $table->unique(['software_license_id', 'computer_id'], 'license_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_license_assignments');
        Schema::dropIfExists('software_licenses');
    }
};
