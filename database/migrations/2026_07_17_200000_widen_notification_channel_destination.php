<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * destination was the default VARCHAR(255), but a Teams or Azure Logic
     * Apps webhook URL routinely runs several hundred characters. Validation
     * allowed up to 500, so such a URL passed the form and then hit a raw
     * "Data too long" 500 at the database. Widen it well past any real
     * webhook URL; email addresses were never near the limit.
     */
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->string('destination', 2048)->change();
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->string('destination', 255)->change();
        });
    }
};
