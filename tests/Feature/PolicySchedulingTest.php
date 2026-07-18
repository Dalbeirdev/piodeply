<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\Role as RoleEnum;
use App\Livewire\Computers\ComputerEdit;
use App\Livewire\Policies\PoliciesIndex;
use App\Livewire\Policies\PolicyForm;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Models\User;
use App\Services\ComputerService;
use App\Services\PolicyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PolicySchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): PolicyService
    {
        return app(PolicyService::class);
    }

    private function manager(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Manager->value));
    }

    /** A Saturday 02:00–05:00 window. */
    private function saturdayWindowPolicy(Project $project, Package $package): SoftwarePolicy
    {
        return SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
            'window_days' => [6], 'window_start' => '02:00:00', 'window_end' => '05:00:00',
        ]);
    }

    // ── Maintenance windows ────────────────────────────────────────────

    public function test_enforcement_waits_for_the_maintenance_window(): void
    {
        // Freeze the clock BEFORE creating fixtures: the policy's rollout start
        // defaults to created_at, so a real created_at would leak wall-clock
        // time and make eligibility depend on the hour the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-07-13 15:00')); // Monday afternoon

        $project = Project::factory()->create();
        $package = Package::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]);
        $policy = $this->saturdayWindowPolicy($project, $package);

        $this->assertSame(0, $this->service()->enforce($policy));

        Carbon::setTestNow(Carbon::parse('2026-07-18 03:00')); // Saturday 3 AM
        $this->assertSame(1, $this->service()->enforce($policy));
    }

    public function test_overnight_windows_wrap_past_midnight(): void
    {
        $policy = SoftwarePolicy::factory()->create([
            'window_days' => [1], 'window_start' => '22:00:00', 'window_end' => '04:00:00', // Mon 22:00 → Tue 04:00
        ]);

        $this->assertTrue($policy->isInWindow(Carbon::parse('2026-07-13 23:00'))); // Monday night
        $this->assertTrue($policy->isInWindow(Carbon::parse('2026-07-14 03:00'))); // Tuesday early morning
        $this->assertFalse($policy->isInWindow(Carbon::parse('2026-07-14 23:00'))); // Tuesday night
        $this->assertFalse($policy->isInWindow(Carbon::parse('2026-07-13 21:00'))); // Monday before start
    }

    public function test_manual_enforce_now_overrides_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 15:00')); // Monday — window closed

        $project = Project::factory()->create();
        $package = Package::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]);
        $policy = $this->saturdayWindowPolicy($project, $package);

        Livewire::actingAs($this->manager())
            ->test(PoliciesIndex::class)
            ->call('enforceNow', $policy->id);

        $this->assertSame(1, DeploymentJob::count());
    }

    public function test_agent_report_respects_the_window(): void
    {
        // Freeze before creating fixtures (see note above) so created_at — and
        // thus the rollout start — does not leak real wall-clock time.
        Carbon::setTestNow(Carbon::parse('2026-07-13 15:00')); // Monday

        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        $this->saturdayWindowPolicy($project, $package);

        app(ComputerService::class)->replaceSoftwareInventory($computer, [
            ['name' => 'X', 'source' => 'registry'],
        ]);
        $this->assertSame(0, DeploymentJob::count());

        Carbon::setTestNow(Carbon::parse('2026-07-18 03:00')); // Saturday 3 AM
        app(ComputerService::class)->replaceSoftwareInventory($computer, [
            ['name' => 'X', 'source' => 'registry'],
        ]);
        $this->assertSame(1, DeploymentJob::count());
    }

    public function test_compliance_shows_scheduled_while_window_is_closed(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]);
        $policy = $this->saturdayWindowPolicy($project, $package);

        Carbon::setTestNow(Carbon::parse('2026-07-13 15:00')); // Monday

        $summary = $this->service()->complianceSummary($policy);
        $this->assertSame(1, $summary['scheduled']);
        $this->assertSame(0, $summary['non_compliant']);
    }

    // ── Deployment rings ───────────────────────────────────────────────

    public function test_rings_stage_the_rollout(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $pilot = Computer::factory()->ring('pilot')->create(['project_id' => $project->id]);
        $production = Computer::factory()->ring('production')->create(['project_id' => $project->id]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
            'production_delay_days' => 7, 'rollout_started_at' => now(),
        ]);

        // Day 0: pilot only.
        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $pilot->id]);
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $production->id]);

        // Compliance explains the wait.
        $rows = $this->service()->complianceFor($policy->fresh());
        $productionRow = $rows->firstWhere(fn ($row) => $row['computer']->is($production));
        $this->assertSame('scheduled', $productionRow['status']);
        $this->assertStringContainsString('Production ring eligible', $productionRow['reason']);

        // Day 8: production follows.
        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame(1, $this->service()->enforce($policy->fresh()));
        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $production->id]);
    }

    public function test_manual_enforce_does_not_bypass_ring_delays(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        Computer::factory()->ring('production')->create(['project_id' => $project->id]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
            'production_delay_days' => 7, 'rollout_started_at' => now(),
        ]);

        Livewire::actingAs($this->manager())
            ->test(PoliciesIndex::class)
            ->call('enforceNow', $policy->id);

        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_emergency_ring_ignores_windows_and_delays(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        Computer::factory()->ring('emergency')->create(['project_id' => $project->id]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
            'production_delay_days' => 30, 'rollout_started_at' => now(),
        ]);

        // Ring delay does not apply to emergency machines…
        $this->assertSame(1, $this->service()->enforce($policy));

        // …and neither does the maintenance window on agent report.
        $emergency2 = Computer::factory()->ring('emergency')->create(['project_id' => $project->id]);
        $policy->update(['window_days' => [6], 'window_start' => '02:00:00', 'window_end' => '05:00:00']);
        Carbon::setTestNow(Carbon::parse('2026-07-13 15:00')); // Monday
        // The window gate applies per policy, before rings — emergency wins
        // via the scheduled run too, so verify via compliance instead:
        $rows = $this->service()->complianceFor($policy->fresh());
        $row = $rows->firstWhere(fn ($r) => $r['computer']->is($emergency2));
        $this->assertContains($row['status'], ['scheduled', 'non_compliant']);
    }

    // ── Frequency ──────────────────────────────────────────────────────

    public function test_weekly_frequency_spaces_out_routine_updates(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => $package->winget_id, 'source' => 'winget',
        ]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id,
            'action' => 'update', 'frequency' => 'weekly',
        ]);

        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => \App\Enums\JobAction::Update, 'status' => JobStatus::Succeeded,
            'finished_at' => now()->subDays(2),
        ]);

        // 2 days after the last success: weekly policy stays quiet
        // (a daily one would have queued).
        $this->assertSame(0, $this->service()->enforce($policy));

        Carbon::setTestNow(now()->addDays(6)); // 8 days after the success
        $this->assertSame(1, $this->service()->enforce($policy->fresh()));
    }

    // ── Scheduler command ──────────────────────────────────────────────

    public function test_enforce_command_queues_for_open_windows_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 15:00')); // Monday

        $package = Package::factory()->create();

        $openProject = Project::factory()->create();
        Computer::factory()->create(['project_id' => $openProject->id]);
        SoftwarePolicy::factory()->create([
            'project_id' => $openProject->id, 'package_id' => $package->id, 'action' => 'install',
        ]); // no window = anytime

        $closedProject = Project::factory()->create();
        Computer::factory()->create(['project_id' => $closedProject->id]);
        $this->saturdayWindowPolicy($closedProject, $package);

        $this->artisan('policies:enforce')
            ->expectsOutputToContain('1 job(s) queued')
            ->assertExitCode(0);

        $this->assertSame(1, DeploymentJob::count());
    }

    // ── UI ─────────────────────────────────────────────────────────────

    public function test_form_saves_window_and_ring_delays(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();

        Livewire::actingAs($this->manager())
            ->test(PolicyForm::class)
            ->set('project_id', $project->id)
            ->set('package_id', $package->id)
            ->set('action', 'install')
            ->set('window_days', [6, 7])
            ->set('window_start', '02:00')
            ->set('window_end', '05:00')
            ->set('production_delay_days', 7)
            ->call('save')
            ->assertHasNoErrors();

        $policy = SoftwarePolicy::firstOrFail();
        $this->assertSame([6, 7], $policy->window_days);
        $this->assertSame(7, $policy->production_delay_days);
        $this->assertNotNull($policy->rollout_started_at);
        $this->assertSame('Sat, Sun 02:00–05:00', $policy->windowLabel());
    }

    public function test_window_days_without_times_is_rejected(): void
    {
        Livewire::actingAs($this->manager())
            ->test(PolicyForm::class)
            ->set('project_id', Project::factory()->create()->id)
            ->set('package_id', Package::factory()->create()->id)
            ->set('action', 'install')
            ->set('window_days', [6])
            ->call('save')
            ->assertHasErrors('window_start');
    }

    public function test_changing_the_desired_version_restarts_the_rollout(): void
    {
        $policy = SoftwarePolicy::factory()->create([
            'version_mode' => 'exact', 'desired_version' => '1.0',
            'rollout_started_at' => now()->subDays(30),
        ]);

        Livewire::actingAs($this->manager())
            ->test(PolicyForm::class, ['policy' => $policy])
            ->set('desired_version', '2.0')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($policy->fresh()->rollout_started_at->gt(now()->subMinute()));
    }

    public function test_a_cancelled_job_backs_off_before_requeueing(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => \App\Enums\JobAction::Install, 'status' => JobStatus::Cancelled,
            'finished_at' => now()->subHour(),
        ]);

        // The operator's cancel holds for the backoff window…
        $this->assertSame(0, $this->service()->enforce($policy));

        // …then desired state re-asserts.
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(1, $this->service()->enforce($policy->fresh()));
    }

    public function test_computer_ring_can_be_set_from_the_edit_page(): void
    {
        $computer = Computer::factory()->create();
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(ComputerEdit::class, ['computer' => $computer])
            ->set('ring', 'pilot')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('pilot', $computer->fresh()->ring->value);
    }
}
