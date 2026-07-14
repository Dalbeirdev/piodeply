<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20); // install | update | uninstall
            $table->unsignedTinyInteger('priority')->default(5);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_enforced_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'package_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_policies');
    }
};
