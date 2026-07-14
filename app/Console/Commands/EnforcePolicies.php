<?php

namespace App\Console\Commands;

use App\Services\PolicyService;
use Illuminate\Console\Command;

class EnforcePolicies extends Command
{
    protected $signature = 'policies:enforce';

    protected $description = 'Enforce every active policy whose maintenance window is open (queues remediation jobs)';

    public function handle(PolicyService $service): int
    {
        $queued = $service->enforceAll();

        $this->info("Policy enforcement complete — {$queued} job(s) queued.");

        return self::SUCCESS;
    }
}
