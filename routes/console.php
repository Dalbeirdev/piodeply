<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Policies whose maintenance window is open queue their remediation jobs
// here; agents pick the jobs up on their next poll. Runs every 5 minutes
// so short windows (e.g. Sat 02:00–05:00) are never missed.
// Requires the Laravel scheduler to be running:
//   php artisan schedule:work   (or a Task Scheduler entry running
//   `php artisan schedule:run` every minute)
Schedule::command('policies:enforce')->everyFiveMinutes();

// One alert per outage (heartbeats re-arm it); default trips after an
// hour offline — see config/piodeploy.php.
Schedule::command('agents:check-offline')->everyFifteenMinutes();

// Morning compliance summary for subscribed channels.
Schedule::command('policies:drift-digest')->dailyAt('08:00');

// Audit-log retention (days configurable in Admin → Settings).
Schedule::command('logs:prune')->dailyAt('03:30');
