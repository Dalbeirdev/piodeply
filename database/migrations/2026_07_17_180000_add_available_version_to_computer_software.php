<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computer_software', function (Blueprint $table) {
            // What the package manager on the machine says is newer. Only it
            // knows: the server has the catalogue and what is installed, and
            // no idea a new Chrome shipped this morning. Null means "nothing
            // newer offered", or an agent too old to report it.
            $table->string('available_version', 100)
                ->nullable()
                ->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('computer_software', function (Blueprint $table) {
            $table->dropColumn('available_version');
        });
    }
};
