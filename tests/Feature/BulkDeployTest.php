<?php

namespace Tests\Feature;

use App\Enums\DeploymentRing;
use App\Enums\InstallerType;
use App\Enums\JobAction;
use App\Enums\Role as RoleEnum;
use App\Livewire\Deployments\BulkDeploy;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fan-out deploys: one package/action across a project, through the same
 * guarded queue as a single machine.
 */
class BulkDeployTest extends TestCase
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

    private function chrome(): Package
    {
        return Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);
    }

    public function test_queue_bulk_queues_a_job_per_machine(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->count(3)->create(['project_id' => $project->id]);
        $chrome = $this->chrome();

        $result = app(DeploymentService::class)->queueBulk(
            Computer::where('project_id', $project->id)->get(),
            $chrome,
            JobAction::Install,
        );

        $this->assertSame(3, $result->queued);
        $this->assertSame(3, $result->total);
        $this->assertSame(3, \App\Models\DeploymentJob::where('action', 'install')->count());
    }

    public function test_queue_bulk_skips_machines_already_satisfied(): void
    {
        $project = Project::factory()->create();
        $computers = Computer::factory()->count(3)->create(['project_id' => $project->id]);
        $chrome = $this->chrome();

        // One machine already has Chrome — a plain install is a no-op there.
        ComputerSoftware::factory()->create([
            'computer_id' => $computers->first()->id, 'name' => 'Google.Chrome', 'version' => '141.0', 'source' => 'winget',
        ]);

        $result = app(DeploymentService::class)->queueBulk(
            Computer::where('project_id', $project->id)->get(),
            $chrome,
            JobAction::Install,
        );

        $this->assertSame(2, $result->queued);
        $this->assertSame(1, $result->skipped);
    }

    public function test_queue_bulk_refuses_an_unsupported_action(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->count(2)->create(['project_id' => $project->id]);
        $portable = Package::factory()->create(['installer_type' => InstallerType::Portable, 'winget_id' => null]);

        $result = app(DeploymentService::class)->queueBulk(
            Computer::where('project_id', $project->id)->get(),
            $portable,
            JobAction::Uninstall,
        );

        $this->assertSame(0, $result->queued);
        $this->assertSame(2, $result->refused);
    }

    public function test_component_deploys_to_a_ring_only(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->count(2)->create(['project_id' => $project->id, 'ring' => DeploymentRing::Pilot]);
        Computer::factory()->create(['project_id' => $project->id, 'ring' => DeploymentRing::Production]);
        $chrome = $this->chrome();

        Livewire::actingAs($this->admin())
            ->test(BulkDeploy::class)
            ->set('projectId', $project->id)
            ->set('packageId', $chrome->id)
            ->set('ring', 'pilot')
            ->assertViewHas('targetCount', 2)
            ->call('queue');

        // Only the two pilot machines got a job.
        $this->assertSame(2, \App\Models\DeploymentJob::where('package_id', $chrome->id)->count());
    }

    public function test_component_requires_deploy_permission(): void
    {
        $viewer = User::factory()->create(); // no roles

        Livewire::actingAs($viewer)
            ->test(BulkDeploy::class)
            ->assertForbidden();
    }
}
