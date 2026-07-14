<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_software', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('version', 100)->nullable();
            $table->string('publisher')->nullable();
            // Detection source: registry | msi | winget | choco
            $table->string('source', 20)->index();
            $table->timestamps();

            $table->index(['computer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_software');
    }
};
