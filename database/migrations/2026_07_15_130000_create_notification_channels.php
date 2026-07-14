<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 10); // email | webhook
            $table->string('destination'); // address or URL
            $table->json('events'); // subscribed event keys
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('computers', function (Blueprint $table) {
            // Set when an offline alert has fired; cleared on next heartbeat
            // so a machine alerts once per outage, not once per check.
            $table->timestamp('offline_notified_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('computers', fn (Blueprint $table) => $table->dropColumn('offline_notified_at'));
        Schema::dropIfExists('notification_channels');
    }
};
