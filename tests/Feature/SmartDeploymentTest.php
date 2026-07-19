<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\QueueOutcome;
use App\Enums\Role as RoleEnum;
use App\Livewire\Deployments\DeployToComputer;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\User;
use App\Services\DeploymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Queueing work that would change nothing is the bug this covers: it fills
 * the deployments list with identical "Install -> Succeeded" rows that never
 * installed anything.
 */
class SmartDeploymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    private function service(): DeploymentService
    {
        return app(DeploymentService::class);
    }

    private function wingetPackage(string $id = 'Google.Chrome'): Package
    {
        return Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => $id]);
    }

    private function installed(Computer $computer, string $id, ?string $version): void
    {
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id,
            'name'        => $id,
            'version'     => $version,
            'source'      => 'winget',
        ]);
    }

    /**
     * The source must follow the installer type, not whichever id is filled
     * in. Chrome legitimately has both ids; a choco package looked up as
     * winget reads as absent on every pass, and the policy reinstalls it
     * forever — the exact loop this whole change set exists to kill.
     */
    public function test_a_choco_package_that_also_has_a_winget_id_is_found_by_its_choco_row(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create([
            'name'           => 'Google Chrome',
            'installer_type' => 'choco',
            'winget_id'      => 'Google.Chrome',  // also set, and irrelevant
            'choco_id'       => 'googlechrome',
        ]);

        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id,
            'name'        => 'googlechrome',
            'version'     => '141.0',
            'source'      => 'choco',
        ]);

        $state = app(\App\Services\InstalledStateService::class)->stateOf($package, $computer);

        $this->assertTrue($state['present']);
        $this->assertSame('141.0', $state['version']);

        // And the guard therefore does not queue a redundant install.
        $this->assertSame(
            QueueOutcome::AlreadySatisfied,
            $this->service()->queueIfNeeded($computer, $package, JobAction::Install)->outcome
        );
    }

    public function test_a_winget_package_is_not_matched_against_a_choco_row(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create([
            'installer_type' => 'winget',
            'winget_id'      => 'Google.Chrome',
            'choco_id'       => 'googlechrome',
        ]);

        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'googlechrome', 'source' => 'choco',
        ]);

        $this->assertFalse(app(\App\Services\InstalledStateService::class)->stateOf($package, $computer)['present']);
    }

    /** A misconfigured entry must not read as absent and loop forever. */
    public function test_a_package_missing_the_id_for_its_own_type_falls_back_to_job_history(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create([
            'installer_type' => 'winget', 'winget_id' => null, 'choco_id' => 'googlechrome',
        ]);

        $state = app(\App\Services\InstalledStateService::class)->stateOf($package, $computer);
        $this->assertFalse($state['present']); // nothing installed yet

        DeploymentJob::factory()->create([
            'computer_id' => $computer->id,
            'package_id'  => $package->id,
            'action'      => JobAction::Install,
            'status'      => JobStatus::Succeeded,
        ]);

        $this->assertTrue(app(\App\Services\InstalledStateService::class)->stateOf($package, $computer)['present']);
    }

    public function test_install_is_skipped_when_the_package_is_already_installed(): void
    {
        $computer = Computer::factory()->create(['hostname' => 'SUSHMITA-L11']);
        $package = $this->wingetPackage();
        $this->installed($computer, 'Google.Chrome', '141.0');

        $result = $this->service()->queueIfNeeded($computer, $package, JobAction::Install);

        $this->assertSame(QueueOutcome::AlreadySatisfied, $result->outcome);
        $this->assertNull($result->job);
        $this->assertStringContainsString('already installed', $result->message);
        $this->assertStringContainsString('141.0', $result->message);
        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_repeating_the_same_request_never_stacks_up_rows(): void
    {
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();
        $this->installed($computer, 'Google.Chrome', '141.0');

        foreach (range(1, 5) as $ignored) {
            $this->service()->queueIfNeeded($computer, $package, JobAction::Install);
        }

        // Five clicks, zero jobs — the screenshot bug.
        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_install_is_queued_when_the_installed_version_is_older_than_the_target(): void
    {
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();
        $this->installed($computer, 'Google.Chrome', '138.0');

        $result = $this->service()->queueIfNeeded(
            $computer, $package, JobAction::Install, targetVersion: '141.0'
        );

        $this->assertTrue($result->queued());
        $this->assertSame('138.0', $result->job->installed_version_before);
        $this->assertSame('141.0', $result->job->target_version);
    }

    public function test_install_is_skipped_when_the_installed_version_already_meets_the_target(): void
    {
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();
        $this->installed($computer, 'Google.Chrome', '141.0');

        $result = $this->service()->queueIfNeeded(
            $computer, $package, JobAction::Install, targetVersion: '138.0'
        );

        $this->assertSame(QueueOutcome::AlreadySatisfied, $result->outcome);
        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_a_second_request_collapses_onto_the_job_still_in_flight(): void
    {
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();

        $first = $this->service()->queueIfNeeded($computer, $package, JobAction::Install);
        $second = $this->service()->queueIfNeeded($computer, $package, JobAction::Install);

        $this->assertTrue($first->queued());
        $this->assertSame(QueueOutcome::AlreadyQueued, $second->outcome);
        $this->assertSame($first->job->id, $second->job->id);
        $this->assertSame(1, DeploymentJob::count());
    }

    public function test_force_reinstalls_software_that_is_already_present(): void
    {
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();
        $this->installed($computer, 'Google.Chrome', '141.0');

        $result = $this->service()->queueIfNeeded($computer, $package, JobAction::Install, force: true);

        $this->assertTrue($result->queued());
        $this->assertSame(1, DeploymentJob::count());
        // A repair still records where the machine started.
        $this->assertSame('141.0', $result->job->installed_version_before);
    }

    public function test_uninstall_is_skipped_when_the_package_is_not_installed(): void
    {
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();

        $result = $this->service()->queueIfNeeded($computer, $package, JobAction::Uninstall);

        $this->assertSame(QueueOutcome::AlreadySatisfied, $result->outcome);
        $this->assertStringContainsString('nothing to remove', $result->message);
        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_an_update_is_always_queued_because_only_the_agent_knows_what_is_current(): void
    {
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();
        $this->installed($computer, 'Google.Chrome', '141.0');

        $result = $this->service()->queueIfNeeded($computer, $package, JobAction::Update);

        $this->assertTrue($result->queued());
    }

    public function test_a_succeeded_install_on_a_blind_scan_machine_reads_as_satisfied(): void
    {
        // This machine reports no winget inventory at all (old agent), so
        // after OUR install succeeded, "install it again" is a no-op — that
        // loop produced seventeen identical jobs in the field. Force remains
        // the operator's escape hatch for a genuinely broken install.
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();

        $first = $this->service()->queueIfNeeded($computer, $package, JobAction::Install);
        $first->job->update(['status' => JobStatus::Succeeded, 'finished_at' => now()]);

        $second = $this->service()->queueIfNeeded($computer, $package, JobAction::Install);
        $this->assertFalse($second->queued());

        $forced = $this->service()->queueIfNeeded($computer, $package, JobAction::Install, force: true);
        $this->assertTrue($forced->queued());
        $this->assertNotSame($first->job->id, $forced->job->id);
    }

    public function test_a_finished_job_does_not_block_a_request_when_the_scan_shows_real_absence(): void
    {
        $computer = Computer::factory()->create();
        // The winget scan works — it sees another app — so absence is real.
        $this->installed($computer, 'Mozilla.Firefox', '130.0');
        $package = $this->wingetPackage();

        $first = $this->service()->queueIfNeeded($computer, $package, JobAction::Install);
        $first->job->update(['status' => JobStatus::Succeeded, 'finished_at' => now()]);

        // Inventory still doesn't show it → the machine is not where we want it.
        $second = $this->service()->queueIfNeeded($computer, $package, JobAction::Install);

        $this->assertTrue($second->queued());
        $this->assertNotSame($first->job->id, $second->job->id);
    }

    public function test_quick_deploy_tells_the_operator_there_was_nothing_to_do(): void
    {
        $computer = Computer::factory()->create(['hostname' => 'DALBEIR']);
        $package = $this->wingetPackage();
        $this->installed($computer, 'Google.Chrome', '141.0');

        Livewire::actingAs($this->admin())
            ->test(DeployToComputer::class, ['computer' => $computer])
            ->set('package_id', $package->id)
            ->set('action', 'install')
            ->call('queue')
            ->assertSee('already installed');

        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_quick_deploy_can_still_force_a_repair(): void
    {
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();
        $this->installed($computer, 'Google.Chrome', '141.0');

        Livewire::actingAs($this->admin())
            ->test(DeployToComputer::class, ['computer' => $computer])
            ->set('package_id', $package->id)
            ->set('action', 'install')
            ->set('force', true)
            ->call('queue');

        $this->assertSame(1, DeploymentJob::count());
    }

    public function test_the_api_reports_nothing_to_do_instead_of_queueing_a_redundant_job(): void
    {
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();
        $this->installed($computer, 'Google.Chrome', '141.0');

        $user = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Manager->value));
        Sanctum::actingAs($user, ['deploy']);

        $this->postJson('/api/v1/deployments', [
            'computer_id' => $computer->id,
            'package_id'  => $package->id,
            'action'      => 'install',
        ])
            ->assertOk() // 200, not 201 — a fleet loop should not treat this as failure
            ->assertJsonPath('outcome', 'already_satisfied');

        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_the_api_still_queues_and_returns_201_when_work_is_needed(): void
    {
        $computer = Computer::factory()->create();
        $package = $this->wingetPackage();

        $user = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Manager->value));
        Sanctum::actingAs($user, ['deploy']);

        $this->postJson('/api/v1/deployments', [
            'computer_id' => $computer->id,
            'package_id'  => $package->id,
            'action'      => 'install',
        ])->assertCreated();

        $this->assertSame(1, DeploymentJob::count());
    }
}
