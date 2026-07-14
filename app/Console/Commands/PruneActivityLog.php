<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

class PruneActivityLog extends Command
{
    protected $signature = 'logs:prune';

    protected $description = 'Delete activity-log entries older than the configured retention period';

    public function handle(SettingsService $settings): int
    {
        $days = (int) $settings->get('retention.activity_days');

        $deleted = Activity::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Pruned {$deleted} activity entr(ies) older than {$days} days.");

        return self::SUCCESS;
    }
}
