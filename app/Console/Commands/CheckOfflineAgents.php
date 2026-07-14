<?php

namespace App\Console\Commands;

use App\Models\Computer;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class CheckOfflineAgents extends Command
{
    protected $signature = 'agents:check-offline';

    protected $description = 'Alert once per outage for agents that have stopped reporting in';

    public function handle(NotificationService $notifications): int
    {
        $offlineAfter = (int) config('piodeploy.notifications.offline_after_minutes', 60);

        $computers = Computer::query()
            ->whereNotNull('last_seen_at') // never-enrolled machines are not outages
            ->where('last_seen_at', '<', now()->subMinutes($offlineAfter))
            ->whereNull('offline_notified_at')
            ->with('project.client')
            ->get();

        foreach ($computers as $computer) {
            $notifications->notify('agent.offline', "Agent offline: {$computer->hostname}", [
                'computer'  => $computer->hostname,
                'client'    => $computer->project->client->company_name,
                'project'   => $computer->project->name,
                'last_seen' => $computer->last_seen_at->format('Y-m-d H:i') . " ({$computer->last_seen_at->diffForHumans()})",
            ]);

            $computer->forceFill(['offline_notified_at' => now()])->saveQuietly();
        }

        $this->info("Offline check complete — {$computers->count()} alert(s) raised.");

        return self::SUCCESS;
    }
}
