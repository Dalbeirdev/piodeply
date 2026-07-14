<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\Role as RoleEnum;
use App\Livewire\Policies\PoliciesIndex;
use App\Livewire\Policies\PolicyForm;
use App\Livewire\Policies\PolicyShow;
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

    private function markInstalled(Computer $computer, Package $package, ?string $version = null): void
    {
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id,
            'name'        => $package->winget_id,
            'source'      => 'winget',
            'version'     => $version,
        ]);
    }

    // ── Core enforcement ───────────────────────────────────────────────

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

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $missing->id, 'action' => 'install', 'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $has->id]);
    }

    public function test_enforcement_is_idempotent_while_a_job_is_in_flight(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]);
        $policy = SoftwarePolicy::factory()->create(['project_id' => $project->id]);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertSame(0, $this->service()->enforce($policy));
        $this->assertSame(1, DeploymentJob::count());
    }

    public function test_uninstall_and_block_target_machines_that_have_the_package(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $has = Computer::factory()->create(['project_id' => $project->id]);
        Computer::factory()->create(['project_id' => $project->id]); // clean
        $this->markInstalled($has, $package);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'block',
        ]);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $has->id, 'action' => 'uninstall',
        ]);
    }

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
            'computer_id' => $computer->id, 'package_id' => $package->id, 'action' => 'install',
        ]);
    }

    // ── Modes ──────────────────────────────────────────────────────────

    public function test_audit_mode_reports_compliance_but_never_queues(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]);

        $policy = SoftwarePolicy::factory()->audit()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        $this->assertSame(0, $this->service()->enforce($policy));
        $this->assertSame(0, DeploymentJob::count());

        $summary = $this->service()->complianceSummary($policy);
        $this->assertSame(1, $summary['non_compliant']);
        $this->assertSame(0.0, $summary['percent']);
    }

    public function test_disabled_mode_is_inert(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]);
        $policy = SoftwarePolicy::factory()->disabled()->create(['project_id' => $project->id]);

        $this->assertSame(0, $this->service()->enforce($policy));

        // Auto-enforcement on software report also skips it.
        app(ComputerService::class)->replaceSoftwareInventory(
            $project->computers()->first(), [['name' => 'X', 'source' => 'registry']]
        );
        $this->assertSame(0, DeploymentJob::count());
    }

    // ── Version control ────────────────────────────────────────────────

    public function test_exact_version_queues_rollback_with_target_version(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $wrong = Computer::factory()->create(['project_id' => $project->id]);
        $right = Computer::factory()->create(['project_id' => $project->id]);
        $this->markInstalled($wrong, $package, '25.00');
        $this->markInstalled($right, $package, '24.09');

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id,
            'action' => 'install', 'version_mode' => 'exact', 'desired_version' => '24.09',
        ]);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $wrong->id, 'action' => 'rollback', 'target_version' => '24.09',
        ]);
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $right->id]);
    }

    public function test_exact_version_install_pins_the_version_for_missing_machines(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id,
            'action' => 'install', 'version_mode' => 'exact', 'desired_version' => '24.09',
        ]);

        $this->service()->enforce($policy);
        $this->assertDatabaseHas('deployment_jobs', [
            'action' => 'install', 'target_version' => '24.09',
        ]);
    }

    public function test_minimum_version_updates_only_machines_below_it(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $old = Computer::factory()->create(['project_id' => $project->id]);
        $new = Computer::factory()->create(['project_id' => $project->id]);
        $this->markInstalled($old, $package, '24.0');
        $this->markInstalled($new, $package, '25.1');

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id,
            'action' => 'update', 'version_mode' => 'minimum', 'desired_version' => '25.1',
        ]);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $old->id, 'action' => 'update']);
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $new->id]);
    }

    public function test_freeze_downgrades_machines_above_the_cap(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $ahead = Computer::factory()->create(['project_id' => $project->id]);
        $this->markInstalled($ahead, $package, '26.0');

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id,
            'action' => 'update', 'version_mode' => 'maximum', 'desired_version' => '25.0',
        ]);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $ahead->id, 'action' => 'rollback', 'target_version' => '25.0',
        ]);
    }

    public function test_force_update_reinstalls_even_when_version_matches(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        $this->markInstalled($computer, $package, '24.09');

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id,
            'action' => 'force_update', 'desired_version' => '24.09',
        ]);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $computer->id, 'action' => 'rollback', 'target_version' => '24.09',
        ]);
    }

    // ── Exclusions ─────────────────────────────────────────────────────

    public function test_excluded_computers_are_skipped(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $excluded = Computer::factory()->create(['project_id' => $project->id]);
        $normal = Computer::factory()->create(['project_id' => $project->id]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);
        $policy->excludedComputers()->attach($excluded->id);

        $this->assertSame(1, $this->service()->enforce($policy));
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $excluded->id]);
        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $normal->id]);

        // Auto-enforcement also honours exclusions.
        app(ComputerService::class)->replaceSoftwareInventory($excluded, [
            ['name' => 'X', 'source' => 'registry'],
        ]);
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $excluded->id]);
    }

    // ── Compliance reporting ───────────────────────────────────────────

    public function test_compliance_summary_buckets_the_fleet(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();

        $compliant = Computer::factory()->create(['project_id' => $project->id]);
        $this->markInstalled($compliant, $package, '1.0');
        $pending = Computer::factory()->create(['project_id' => $project->id]);
        $failed = Computer::factory()->create(['project_id' => $project->id]);
        $drifted = Computer::factory()->create(['project_id' => $project->id]);
        $excluded = Computer::factory()->create(['project_id' => $project->id]);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);
        $policy->excludedComputers()->attach($excluded->id);

        DeploymentJob::factory()->create([
            'computer_id' => $pending->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Pending,
        ]);
        DeploymentJob::factory()->create([
            'computer_id' => $failed->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Failed, 'finished_at' => now()->subHour(),
        ]);

        $summary = $this->service()->complianceSummary($policy);

        $this->assertSame(4, $summary['target']);
        $this->assertSame(1, $summary['compliant']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['non_compliant']);
        $this->assertSame(1, $summary['excluded']);
        $this->assertSame(25.0, $summary['percent']);
    }

    public function test_policy_show_page_renders_with_drilldown(): void
    {
        $policy = SoftwarePolicy::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $policy->project_id, 'hostname' => 'DRIFTED-PC']);

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(PolicyShow::class, ['policy' => $policy])
            ->assertSee('DRIFTED-PC')
            ->assertSee('Non-compliant')
            ->call('filterBy', 'compliant')
            ->assertDontSee('DRIFTED-PC');
    }

    public function test_exclusion_can_be_toggled_from_the_policy_page(): void
    {
        $policy = SoftwarePolicy::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $policy->project_id]);

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(PolicyShow::class, ['policy' => $policy])
            ->call('toggleExclusion', $computer->id);

        $this->assertTrue($policy->excludedComputers()->whereKey($computer->id)->exists());
    }

    // ── Form & authorization ───────────────────────────────────────────

    public function test_manager_can_create_a_versioned_policy(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(PolicyForm::class)
            ->set('project_id', $project->id)
            ->set('package_id', $package->id)
            ->set('action', 'install')
            ->set('version_mode', 'exact')
            ->set('desired_version', '24.09')
            ->set('priority', 1)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('policies.index'));

        $this->assertDatabaseHas('software_policies', [
            'version_mode' => 'exact', 'desired_version' => '24.09', 'priority' => 1,
        ]);
    }

    public function test_version_pinning_requires_a_version_and_a_winget_package(): void
    {
        $project = Project::factory()->create();
        $winget = Package::factory()->create();
        $msi = Package::factory()->msi()->create();
        $manager = $this->userWithRole(RoleEnum::Manager);

        Livewire::actingAs($manager)
            ->test(PolicyForm::class)
            ->set('project_id', $project->id)
            ->set('package_id', $winget->id)
            ->set('action', 'install')
            ->set('version_mode', 'exact')
            ->call('save')
            ->assertHasErrors('desired_version');

        Livewire::actingAs($manager)
            ->test(PolicyForm::class)
            ->set('project_id', $project->id)
            ->set('package_id', $msi->id)
            ->set('action', 'install')
            ->set('version_mode', 'exact')
            ->set('desired_version', '1.0')
            ->call('save')
            ->assertHasErrors('version_mode');
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

    public function test_index_toggle_flips_between_disabled_and_enforce(): void
    {
        $policy = SoftwarePolicy::factory()->create();

        $component = Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(PoliciesIndex::class);

        $component->call('toggle', $policy->id);
        $this->assertSame('disabled', $policy->fresh()->mode->value);

        $component->call('toggle', $policy->id);
        $this->assertSame('enforce', $policy->fresh()->mode->value);
    }

    public function test_enforce_now_from_index_queues_jobs(): void
    {
        $policy = SoftwarePolicy::factory()->create();
        Computer::factory()->count(2)->create(['project_id' => $policy->project_id]);

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(PoliciesIndex::class)
            ->call('enforceNow', $policy->id);

        $this->assertSame(2, DeploymentJob::where('status', JobStatus::Pending)->count());
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
        $this->actingAs($viewer)->get("/policies/{$policy->id}")->assertOk();
        $this->actingAs($viewer)->get('/policies/create')->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(PoliciesIndex::class)
            ->call('delete', $policy->id)
            ->assertForbidden();
    }

    // ── Agent payload ──────────────────────────────────────────────────

    public function test_agent_job_payload_carries_the_pinned_version(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        $this->markInstalled($computer, $package, '25.0');

        SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id,
            'action' => 'install', 'version_mode' => 'exact', 'desired_version' => '24.09',
        ]);

        app(PolicyService::class)->enforceForComputer($computer);

        $job = DeploymentJob::firstOrFail();
        $this->assertSame('rollback', $job->action->value);
        $this->assertSame('24.09', $job->target_version);
    }
}
