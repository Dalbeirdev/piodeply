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
