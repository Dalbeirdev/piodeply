<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);            // contact | access_request
            $table->string('name');
            $table->string('email');
            $table->string('company')->nullable();
            $table->string('fleet_size', 20)->nullable();
            $table->text('message')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
