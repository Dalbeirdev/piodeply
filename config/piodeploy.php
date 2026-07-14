<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent behaviour
    |--------------------------------------------------------------------------
    | Agents heartbeat every 60 seconds. A computer is considered online when
    | its last heartbeat is within the threshold below (missed-beat slack).
    */

    'agent' => [
        'online_threshold_seconds' => env('PIODEPLOY_ONLINE_THRESHOLD', 300),
        'heartbeat_seconds'        => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    | An "agent offline" alert fires after this many minutes of silence —
    | deliberately far above the online threshold so short blips stay quiet.
    */

    'notifications' => [
        'offline_after_minutes' => env('PIODEPLOY_OFFLINE_ALERT_MINUTES', 60),
    ],

];
