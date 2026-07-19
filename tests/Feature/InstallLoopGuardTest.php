<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Services\InstalledStateService;
use App\Services\PolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Install ×17" loop: a machine whose agent cannot scan winget reports
 * no winget inventory at all, the policy read that as "not installed", and
 * queued the same install every pass — each run answering "already
 * installed, nothing changed". Two guards kill it: blind-scan machines fall
 * back to our own job history, and installs are paced one per frequency
 * window regardless.
 */
class InstallLoopGuardTest extends TestCase
{
    use RefreshDatabase;

    private function chromePolicy(Project $project): array
    {
        $chrome = Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);
        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $chrome->id, 'action' => 'install',
        ]);

        return [$chrome, $policy];
    }

    private function succeededInstall(Computer $computer, Package $package, int $hoursAgo = 1): DeploymentJob
    {
        return DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Succeeded,
            'finished_at' => now()->subHours($hoursAgo),
        ]);
    }

    public function test_a_blind_scan_machine_counts_our_succeeded_install_as_present(): void
    {
        $computer = Computer::factory()->create(); // reports NO winget rows at all
        $chrome = Package::factory()->create(['winget_id' => 'Google.Chrome']);
        $this->succeededInstall($computer, $chrome);

        $state = app(InstalledStateService::class)->stateOf($chrome, $computer);

        $this->assertTrue($state['present']);
        $this->assertNull($state['version']); // honest: we can't know which
    }

    public function test_a_working_scan_still_reports_genuine_absence(): void
    {
        $computer = Computer::factory()->create();
        // The scan works — it sees another winget app — but not Chrome.
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Mozilla.Firefox', 'source' => 'winget',
        ]);
        $chrome = Package::factory()->create(['winget_id' => 'Google.Chrome']);
        $this->succeededInstall($computer, $chrome, hoursAgo: 100); // long ago, and user removed it since

        $state = app(InstalledStateService::class)->stateOf($chrome, $computer);

        $this->assertFalse($state['present']); // inventory is authoritative when it works
    }

    public function test_the_policy_loop_stops_after_one_successful_install(): void
    {
        $project = Project::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]); // blind scan
        [$chrome, $policy] = $this->chromePolicy($project);

        // First pass: nothing installed, no history → queues exactly one job.
        $this->assertSame(1, app(PolicyService::class)->enforce($policy));
        $job = DeploymentJob::firstOrFail();

        // The agent reports success ("already installed — nothing changed").
        $job->update(['status' => JobStatus::Succeeded, 'finished_at' => now()]);

        // Every subsequent pass: presence comes from job history → no re-queue.
        $this->assertSame(0, app(PolicyService::class)->enforce($policy));
        $this->assertSame(0, app(PolicyService::class)->enforce($policy));
        $this->assertSame(1, DeploymentJob::count()); // still just the one
    }

    public function test_installs_are_paced_even_when_the_state_reads_absent(): void
    {
        $project = Project::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        // Scan works and shows Chrome truly absent…
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Mozilla.Firefox', 'source' => 'winget',
        ]);
        [$chrome, $policy] = $this->chromePolicy($project);

        // …but we already installed successfully two hours ago (inventory lag).
        $this->succeededInstall($computer, $chrome, hoursAgo: 2);

        // Within the frequency window: paced, no hammering.
        $this->assertSame(0, app(PolicyService::class)->enforce($policy));

        // Once the window passes, desired state re-asserts normally.
        DeploymentJob::query()->update(['finished_at' => now()->subHours(48)]);
        $this->assertSame(1, app(PolicyService::class)->enforce($policy));
    }
}
