<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\Role as RoleEnum;
use App\Livewire\Reports\ComplianceReport;
use App\Livewire\Reports\ComputersReport;
use App\Livewire\Reports\DeploymentsReport;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(RoleEnum $role, ?int $clientId = null): User
    {
        return tap(
            User::factory()->create(['client_id' => $clientId]),
            fn (User $u) => $u->assignRole($role->value)
        );
    }

    public function test_reports_hub_and_pages_open_for_report_viewers(): void
    {
        $viewer = $this->userWithRole(RoleEnum::Viewer);

        foreach (['/reports', '/reports/compliance', '/reports/deployments', '/reports/computers'] as $url) {
            $this->actingAs($viewer)->get($url)->assertOk();
        }
    }

    public function test_users_without_reports_view_are_blocked(): void
    {
        // No role at all → no reports.view.
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports')->assertForbidden();
    }

    public function test_compliance_report_aggregates_policies(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $compliant = Computer::factory()->create(['project_id' => $project->id]);
        Computer::factory()->create(['project_id' => $project->id]); // drifted
        ComputerSoftware::factory()->create([
            'computer_id' => $compliant->id, 'name' => $package->winget_id, 'source' => 'winget',
        ]);

        SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);
        SoftwarePolicy::factory()->disabled()->create(['project_id' => $project->id]); // hidden

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(ComplianceReport::class)
            ->assertViewHas('overall', fn ($overall) => $overall['policies'] === 1
                && $overall['target'] === 2
                && $overall['compliant'] === 1
                && $overall['percent'] === 50.0);
    }

    public function test_deployments_report_counts_and_success_rate(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create();

        DeploymentJob::factory()->count(3)->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Succeeded,
        ]);
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Failed,
        ]);
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Pending,
        ]);

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(DeploymentsReport::class)
            ->assertViewHas('stats', fn ($stats) => $stats['total'] === 5
                && $stats['succeeded'] === 3
                && $stats['failed'] === 1
                && $stats['in_flight'] === 1
                && $stats['success_rate'] === 75.0);
    }

    public function test_deployment_export_produces_csv_and_respects_permission(): void
    {
        $computer = Computer::factory()->create(['hostname' => 'CSV-PC']);
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id,
            'package_id' => Package::factory()->create(['name' => 'CsvApp'])->id,
            'action' => JobAction::Install, 'status' => JobStatus::Succeeded,
        ]);

        // Manager (has reports.export) gets a CSV containing the job.
        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(DeploymentsReport::class)
            ->call('export')
            ->assertFileDownloaded();

        // Content check: invoke the component directly and capture the stream.
        $this->actingAs($this->userWithRole(RoleEnum::Manager));
        $component = new DeploymentsReport();
        $component->mount();
        ob_start();
        $component->export()->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('CSV-PC', $csv);
        $this->assertStringContainsString('CsvApp', $csv);

        // Viewer (view only) is refused.
        Livewire::actingAs($this->userWithRole(RoleEnum::Viewer))
            ->test(DeploymentsReport::class)
            ->call('export')
            ->assertForbidden();
    }

    public function test_computers_report_filters_by_ring_and_presence(): void
    {
        Computer::factory()->ring('pilot')->online()->create(['hostname' => 'PILOT-ON']);
        Computer::factory()->ring('production')->offline()->create(['hostname' => 'PROD-OFF']);

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(ComputersReport::class)
            ->assertSee('PILOT-ON')->assertSee('PROD-OFF')
            ->set('ringFilter', 'pilot')
            ->assertSee('PILOT-ON')->assertDontSee('PROD-OFF')
            ->set('ringFilter', '')
            ->set('presence', 'offline')
            ->assertSee('PROD-OFF')->assertDontSee('PILOT-ON');
    }

    public function test_reports_are_tenant_scoped(): void
    {
        $acme = Client::factory()->create();
        $globex = Client::factory()->create();
        $acmeProject = Project::factory()->for($acme)->create();
        $globexProject = Project::factory()->for($globex)->create();
        $acmePc = Computer::factory()->create(['project_id' => $acmeProject->id, 'hostname' => 'ACME-PC']);
        $globexPc = Computer::factory()->create(['project_id' => $globexProject->id, 'hostname' => 'GLOBEX-PC']);

        $package = Package::factory()->create();
        DeploymentJob::factory()->create(['computer_id' => $acmePc->id, 'package_id' => $package->id, 'action' => JobAction::Install, 'status' => JobStatus::Succeeded]);
        DeploymentJob::factory()->create(['computer_id' => $globexPc->id, 'package_id' => $package->id, 'action' => JobAction::Install, 'status' => JobStatus::Succeeded]);
        SoftwarePolicy::factory()->create(['project_id' => $acmeProject->id, 'package_id' => $package->id]);
        SoftwarePolicy::factory()->create(['project_id' => $globexProject->id, 'package_id' => $package->id]);

        $acmeUser = $this->userWithRole(RoleEnum::Client, $acme->id);

        $this->actingAs($acmeUser)->get('/reports/computers')->assertOk();

        Livewire::actingAs($acmeUser)
            ->test(ComputersReport::class)
            ->assertSee('ACME-PC')->assertDontSee('GLOBEX-PC');

        Livewire::actingAs($acmeUser)
            ->test(DeploymentsReport::class)
            ->assertViewHas('stats', fn ($stats) => $stats['total'] === 1);

        Livewire::actingAs($acmeUser)
            ->test(ComplianceReport::class)
            ->assertViewHas('overall', fn ($overall) => $overall['policies'] === 1);
    }

    public function test_policy_show_lists_change_history(): void
    {
        $manager = $this->userWithRole(RoleEnum::Manager);
        $policy = SoftwarePolicy::factory()->create();
        $this->actingAs($manager);
        $policy->update(['priority' => 1]); // logged as 'updated'

        Livewire::actingAs($manager)
            ->test(\App\Livewire\Policies\PolicyShow::class, ['policy' => $policy])
            ->assertSee('Change history')
            ->assertSee('Updated')
            ->assertSee('priority');
    }
}
