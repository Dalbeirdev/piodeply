<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Read and handled are different states: you can read an enquiry
            // the moment it arrives and still have work left to do on it.
            $table->timestamp('read_at')->nullable()->after('handled_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
