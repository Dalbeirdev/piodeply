<?php

namespace Tests\Feature;

use App\Enums\InstallerType;
use App\Enums\JobAction;
use App\Enums\QueueOutcome;
use App\Livewire\Deployments\DeployToComputer;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Models\User;
use App\Enums\Role as RoleEnum;
use App\Services\DeploymentService;
use App\Services\PolicyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rollback is a version-pinned reinstall that only a package manager
 * (winget / Chocolatey) can perform. These lock down "which application can
 * roll back or not" at every layer: the capability matrix, the queue guard,
 * the deploy form and the policy engine.
 */
class RollbackCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_capability_matrix(): void
    {
        // Only package managers can roll back.
        $this->assertTrue(InstallerType::Winget->supportsRollback());
        $this->assertTrue(InstallerType::Choco->supportsRollback());
        $this->assertFalse(InstallerType::Msi->supportsRollback());
        $this->assertFalse(InstallerType::Exe->supportsRollback());
        $this->assertFalse(InstallerType::Zip->supportsRollback());
        $this->assertFalse(InstallerType::Portable->supportsRollback());

        // Managed uninstall: winget, choco and MSI only.
        $this->assertTrue(InstallerType::Msi->supportsUninstall());
        $this->assertFalse(InstallerType::Portable->supportsUninstall());

        // Install / update / repair are universal.
        $this->assertTrue(InstallerType::Exe->supports(JobAction::Install));
        $this->assertTrue(InstallerType::Exe->supports(JobAction::Update));
        $this->assertFalse(InstallerType::Exe->supports(JobAction::Rollback));
    }

    public function test_queue_refuses_rollback_on_a_non_package_manager_type(): void
    {
        $computer = Computer::factory()->create();
        $msi = Package::factory()->msi()->create(['name' => 'Acme Tool']);

        $result = app(DeploymentService::class)->queueIfNeeded(
            computer: $computer,
            package: $msi,
            action: JobAction::Rollback,
            targetVersion: '1.0.0',
        );

        $this->assertSame(QueueOutcome::Invalid, $result->outcome);
        $this->assertStringContainsString('rollback only works for winget and Chocolatey', $result->message);
        $this->assertDatabaseCount('deployment_jobs', 0);
    }

    public function test_queue_refuses_uninstall_on_a_portable_package(): void
    {
        $computer = Computer::factory()->create();
        $portable = Package::factory()->create(['installer_type' => InstallerType::Portable, 'winget_id' => null]);

        $result = app(DeploymentService::class)->queueIfNeeded(
            computer: $computer,
            package: $portable,
            action: JobAction::Uninstall,
        );

        $this->assertSame(QueueOutcome::Invalid, $result->outcome);
        $this->assertDatabaseCount('deployment_jobs', 0);
    }

    public function test_queue_allows_rollback_on_winget_with_a_target(): void
    {
        $computer = Computer::factory()->create();
        $chrome = Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Google.Chrome', 'version' => '141.0', 'source' => 'winget',
        ]);

        $result = app(DeploymentService::class)->queueIfNeeded(
            computer: $computer,
            package: $chrome,
            action: JobAction::Rollback,
            targetVersion: '138.0',
        );

        $this->assertSame(QueueOutcome::Queued, $result->outcome);
        $this->assertDatabaseHas('deployment_jobs', ['action' => 'rollback', 'target_version' => '138.0']);
    }

    public function test_deploy_form_hides_rollback_for_an_msi_package(): void
    {
        $computer = Computer::factory()->create();
        $msi = Package::factory()->msi()->create();

        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(DeployToComputer::class, ['computer' => $computer])
            ->set('package_id', $msi->id)
            ->assertViewHas('actions', function (array $actions) {
                $values = array_map(fn (JobAction $a) => $a->value, $actions);

                // MSI can install/update/repair/uninstall but not roll back.
                return in_array('install', $values, true)
                    && in_array('uninstall', $values, true)
                    && ! in_array('rollback', $values, true);
            });
    }

    public function test_switching_to_an_msi_resets_a_rollback_action(): void
    {
        $computer = Computer::factory()->create();
        $winget = Package::factory()->create(['winget_id' => 'Google.Chrome']);
        $msi = Package::factory()->msi()->create();

        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(DeployToComputer::class, ['computer' => $computer])
            ->set('package_id', $winget->id)
            ->set('action', 'rollback')
            ->set('package_id', $msi->id)
            ->assertSet('action', 'install');
    }

    public function test_force_update_policy_on_a_binary_type_reinstalls_instead_of_rolling_back(): void
    {
        $project = Project::factory()->create();
        $msi = Package::factory()->msi()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);

        // A binary package reads as "installed" from a prior succeeded install.
        \App\Models\DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $msi->id,
            'action' => JobAction::Install, 'status' => \App\Enums\JobStatus::Succeeded,
        ]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $msi->id,
            'action' => 'force_update', 'desired_version' => '2.0',
        ]);

        app(PolicyService::class)->enforce($policy);

        // A fresh reinstall (Pending install), not a rollback the MSI can't do.
        $this->assertDatabaseHas('deployment_jobs', [
            'package_id' => $msi->id, 'action' => 'install', 'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('deployment_jobs', ['package_id' => $msi->id, 'action' => 'rollback']);
    }

    /* ---- One-click rollback to the last known-good version ---- */

    private function chromeOn(Computer $computer, string $installed): Package
    {
        $chrome = Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Google.Chrome', 'version' => $installed, 'source' => 'winget',
        ]);

        return $chrome;
    }

    public function test_previous_good_version_reads_the_pre_change_version(): void
    {
        $computer = Computer::factory()->create();
        $chrome = $this->chromeOn($computer, '141.0');

        \App\Models\DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $chrome->id,
            'action' => JobAction::Update, 'status' => \App\Enums\JobStatus::Succeeded,
            'installed_version_before' => '138.0', 'installed_version_after' => '141.0',
        ]);

        $this->assertSame('138.0', app(DeploymentService::class)->previousGoodVersion($chrome, $computer));
    }

    public function test_previous_good_version_is_null_without_history(): void
    {
        $computer = Computer::factory()->create();
        $chrome = $this->chromeOn($computer, '141.0');

        $this->assertNull(app(DeploymentService::class)->previousGoodVersion($chrome, $computer));
    }

    public function test_previous_good_version_is_null_for_binary_types(): void
    {
        $computer = Computer::factory()->create();
        $msi = Package::factory()->msi()->create();
        \App\Models\DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $msi->id,
            'action' => JobAction::Install, 'status' => \App\Enums\JobStatus::Succeeded,
            'installed_version_before' => '1.0',
        ]);

        $this->assertNull(app(DeploymentService::class)->previousGoodVersion($msi, $computer));
    }

    public function test_one_click_rollback_queues_a_rollback_to_the_previous_version(): void
    {
        $computer = Computer::factory()->create();
        $chrome = $this->chromeOn($computer, '141.0');
        \App\Models\DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $chrome->id,
            'action' => JobAction::Update, 'status' => \App\Enums\JobStatus::Succeeded,
            'installed_version_before' => '138.0', 'installed_version_after' => '141.0',
        ]);

        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(DeployToComputer::class, ['computer' => $computer])
            ->set('package_id', $chrome->id)
            ->assertSee('Roll back to 138.0')
            ->call('rollbackToPrevious');

        $this->assertDatabaseHas('deployment_jobs', [
            'package_id' => $chrome->id, 'action' => 'rollback', 'target_version' => '138.0', 'status' => 'pending',
        ]);
    }
}
