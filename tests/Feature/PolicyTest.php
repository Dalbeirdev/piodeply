<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\Role as RoleEnum;
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
use Livewire\Livewire;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function service(): PolicyService
    {
        return app(PolicyService::class);
    }

    private function userWithRole(RoleEnum $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole($role->value));
    }

    private function markInstalled(Computer $computer, Package $package): void
    {
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id,
            'name'        => $package->winget_id,
            'source'      => 'winget',
        ]);
    }

    // ── Enforcement engine ─────────────────────────────────────────────

    public function test_install_policy_queues_only_for_machines_missing_the_package(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $missing = Computer::factory()->create(['project_id' => $project->id]);
        $has = Computer::factory()->create(['project_id' => $project->id]);
        $this->markInstalled($has, $package);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        $queued = $this->service()->enforce($policy);

        $this->assertSame(1, $queued);
        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $missing->id, 'package_id' => $package->id,
            'action' => 'install', 'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $has->id]);
        $this->assertNotNull($policy->fresh()->last_enforced_at);
    }

    public function test_enforcement_is_idempotent_while_a_job_is_in_flight(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertSame(0, $this->service()->enforce($policy)); // pending job exists
        $this->assertSame(1, DeploymentJob::count());
    }

    public function test_recently_failed_install_is_not_requeued_immediately(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);

        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Failed,
            'finished_at' => now()->subHour(),
        ]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        $this->assertSame(0, $this->service()->enforce($policy));
    }

    public function test_update_policy_targets_only_machines_that_have_the_package(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $has = Computer::factory()->create(['project_id' => $project->id]);
        $missing = Computer::factory()->create(['project_id' => $project->id]);
        $this->markInstalled($has, $package);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'update',
        ]);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $has->id, 'action' => 'update', 'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $missing->id]);
    }

    public function test_update_policy_respects_the_cooldown_after_a_recent_success(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        $this->markInstalled($computer, $package);

        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Update, 'status' => JobStatus::Succeeded,
            'finished_at' => now()->subHours(2),
        ]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'update',
        ]);

        $this->assertSame(0, $this->service()->enforce($policy));

        // Outside the window it queues again.
        DeploymentJob::query()->update(['finished_at' => now()->subDays(2)]);
        $this->assertSame(1, $this->service()->enforce($policy));
    }

    public function test_uninstall_policy_targets_machines_that_have_the_package(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $has = Computer::factory()->create(['project_id' => $project->id]);
        Computer::factory()->create(['project_id' => $project->id]); // clean machine

        $this->markInstalled($has, $package);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'uninstall',
        ]);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $has->id, 'action' => 'uninstall',
        ]);
    }

    public function test_inactive_policy_and_inactive_package_queue_nothing(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]);

        $disabled = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'is_active' => false,
        ]);
        $deadPackage = SoftwarePolicy::factory()->create([
            'project_id' => $project->id,
            'package_id' => Package::factory()->inactive()->create()->id,
        ]);

        $this->assertSame(0, $this->service()->enforce($disabled));
        $this->assertSame(0, $this->service()->enforce($deadPackage));
        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_binary_package_detection_falls_back_to_job_history(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->msi()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        // No successful install on record → queue.
        $this->assertSame(1, $this->service()->enforce($policy));

        DeploymentJob::query()->update(['status' => JobStatus::Succeeded, 'finished_at' => now()]);

        // Now considered installed → nothing more to do.
        $this->assertSame(0, $this->service()->enforce($policy));
    }

    public function test_policies_only_apply_to_their_own_project(): void
    {
        $package = Package::factory()->create();
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        Computer::factory()->create(['project_id' => $projectA->id]);
        $outside = Computer::factory()->create(['project_id' => $projectB->id]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $projectA->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $outside->id]);
    }

    // ── Auto-enforcement on agent software report ──────────────────────

    public function test_software_report_triggers_enforcement_for_that_machine(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);

        SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        app(ComputerService::class)->replaceSoftwareInventory($computer, [
            ['name' => 'Unrelated App', 'source' => 'registry'],
        ]);

        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => 'install', 'status' => 'pending',
        ]);
    }

    public function test_software_report_showing_compliance_queues_nothing(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);

        SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        app(ComputerService::class)->replaceSoftwareInventory($computer, [
            ['name' => $package->winget_id, 'source' => 'winget'],
        ]);

        $this->assertSame(0, DeploymentJob::count());
    }

    // ── UI & authorization ─────────────────────────────────────────────

    public function test_manager_can_create_a_policy(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(PolicyForm::class)
            ->set('project_id', $project->id)
            ->set('package_id', $package->id)
            ->set('action', 'update')
            ->set('priority', 3)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('policies.index'));

        $this->assertDatabaseHas('software_policies', [
            'project_id' => $project->id, 'package_id' => $package->id,
            'action' => 'update', 'priority' => 3,
        ]);
    }

    public function test_duplicate_policy_is_rejected(): void
    {
        $existing = SoftwarePolicy::factory()->create();

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(PolicyForm::class)
            ->set('project_id', $existing->project_id)
            ->set('package_id', $existing->package_id)
            ->set('action', $existing->action->value)
            ->call('save')
            ->assertHasErrors('package_id');

        $this->assertSame(1, SoftwarePolicy::count());
    }

    public function test_enforce_now_button_queues_jobs_and_reports_count(): void
    {
        $policy = SoftwarePolicy::factory()->create();
        Computer::factory()->count(2)->create(['project_id' => $policy->project_id]);

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(PoliciesIndex::class)
            ->call('enforceNow', $policy->id);

        $this->assertSame(2, DeploymentJob::where('status', JobStatus::Pending)->count());
    }

    public function test_toggle_flips_active_state(): void
    {
        $policy = SoftwarePolicy::factory()->create(['is_active' => true]);

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(PoliciesIndex::class)
            ->call('toggle', $policy->id);

        $this->assertFalse($policy->fresh()->is_active);
    }

    public function test_technician_and_client_cannot_open_policies(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Technician))
            ->get('/policies')->assertForbidden();

        $client = User::factory()->create(['client_id' => \App\Models\Client::factory()->create()->id]);
        $client->assignRole(RoleEnum::Client->value);
        $this->actingAs($client)->get('/policies')->assertForbidden();
    }

    public function test_viewer_can_see_policies_but_not_manage_them(): void
    {
        $policy = SoftwarePolicy::factory()->create();
        $viewer = $this->userWithRole(RoleEnum::Viewer);

        $this->actingAs($viewer)->get('/policies')->assertOk();
        $this->actingAs($viewer)->get('/policies/create')->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(PoliciesIndex::class)
            ->call('delete', $policy->id)
            ->assertForbidden();

        $this->assertSame(1, SoftwarePolicy::count());
    }
}
