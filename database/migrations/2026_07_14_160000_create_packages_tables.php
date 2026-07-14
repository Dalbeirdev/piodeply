<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('vendor')->nullable();
            $table->string('homepage')->nullable();
            $table->text('description')->nullable();
            $table->string('license', 100)->nullable();
            $table->string('installer_type', 20)->index();
            $table->string('architecture', 10)->default('x64');
            $table->string('winget_id')->nullable();
            $table->string('choco_id')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('package_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('version', 100);
            $table->string('installer_url', 2048)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('silent_args')->nullable();
            $table->string('uninstall_args')->nullable();
            $table->date('release_date')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->boolean('is_latest')->default(false)->index();
            $table->timestamps();

            $table->unique(['package_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_versions');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('package_categories');
    }
};
