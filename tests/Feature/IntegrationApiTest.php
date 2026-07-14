<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\Role as RoleEnum;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
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

    public function test_requests_without_a_token_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/computers')->assertUnauthorized();
        $this->postJson('/api/v1/deployments')->assertUnauthorized();
    }

    public function test_read_token_lists_computers(): void
    {
        Computer::factory()->create(['hostname' => 'API-PC']);
        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['read']);

        $this->getJson('/api/v1/computers')
            ->assertOk()
            ->assertJsonPath('data.0.hostname', 'API-PC')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_token_without_the_read_ability_is_refused(): void
    {
        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['deploy']);

        $this->getJson('/api/v1/computers')->assertForbidden();
    }

    public function test_token_cannot_exceed_the_owners_role(): void
    {
        // A Client-role user holds no clients.view — even a 'read' token
        // cannot open the client directory.
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithRole(RoleEnum::Client, $client->id), ['read']);

        $this->getJson('/api/v1/clients')->assertForbidden();
        $this->getJson('/api/v1/computers')->assertOk(); // computers.view is granted
    }

    public function test_lists_are_tenant_scoped_and_foreign_records_read_as_404(): void
    {
        $acme = Client::factory()->create();
        $globex = Client::factory()->create();
        $acmePc = Computer::factory()->create([
            'project_id' => Project::factory()->for($acme)->create()->id, 'hostname' => 'ACME-PC',
        ]);
        $globexPc = Computer::factory()->create([
            'project_id' => Project::factory()->for($globex)->create()->id, 'hostname' => 'GLOBEX-PC',
        ]);

        Sanctum::actingAs($this->userWithRole(RoleEnum::Client, $acme->id), ['read']);

        $response = $this->getJson('/api/v1/computers')->assertOk();
        $hostnames = collect($response->json('data'))->pluck('hostname');
        $this->assertTrue($hostnames->contains('ACME-PC'));
        $this->assertFalse($hostnames->contains('GLOBEX-PC'));

        $this->getJson("/api/v1/computers/{$globexPc->id}")->assertNotFound();
        $this->getJson("/api/v1/computers/{$acmePc->id}")->assertOk();
    }

    public function test_projects_never_leak_key_material(): void
    {
        Project::factory()->create();
        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['read']);

        $json = $this->getJson('/api/v1/projects')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('api_key_hash', $json);
        $this->assertArrayNotHasKey('api_key_prefix', $json);
        $this->assertArrayNotHasKey('download_token', $json);
    }

    public function test_computer_detail_includes_software_on_request(): void
    {
        $computer = Computer::factory()->create();
        ComputerSoftware::factory()->create(['computer_id' => $computer->id, 'name' => 'ApiSeen.App']);
        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['read']);

        $this->getJson("/api/v1/computers/{$computer->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.software');

        $this->getJson("/api/v1/computers/{$computer->id}?with_software=1")
            ->assertOk()
            ->assertJsonPath('data.software.0.name', 'ApiSeen.App');
    }

    public function test_deploy_token_queues_a_job(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create();
        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['deploy']);

        $this->postJson('/api/v1/deployments', [
            'computer_id' => $computer->id,
            'package_id'  => $package->id,
            'action'      => 'install',
            'priority'    => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.priority', 2);

        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $computer->id, 'action' => 'install', 'status' => 'pending',
        ]);
    }

    public function test_read_only_token_cannot_deploy(): void
    {
        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['read']);

        $this->postJson('/api/v1/deployments', [
            'computer_id' => Computer::factory()->create()->id,
            'package_id'  => Package::factory()->create()->id,
            'action'      => 'install',
        ])->assertForbidden();
    }

    public function test_deploy_ability_still_requires_the_role_permission(): void
    {
        // Viewer has no deployments.manage — a deploy token doesn't help.
        Sanctum::actingAs($this->userWithRole(RoleEnum::Viewer), ['deploy']);

        $this->postJson('/api/v1/deployments', [
            'computer_id' => Computer::factory()->create()->id,
            'package_id'  => Package::factory()->create()->id,
            'action'      => 'install',
        ])->assertForbidden();
    }

    public function test_deploying_to_a_foreign_tenant_computer_reads_as_404(): void
    {
        $acme = Client::factory()->create();
        $globexPc = Computer::factory()->create(); // other client entirely

        // A client-bound manager-ish scenario: give a Client-role user a deploy
        // token — role lacks deployments.manage, so use a bound Manager instead.
        $boundManager = $this->userWithRole(RoleEnum::Manager, $acme->id);
        Sanctum::actingAs($boundManager, ['deploy']);

        $this->postJson('/api/v1/deployments', [
            'computer_id' => $globexPc->id,
            'package_id'  => Package::factory()->create()->id,
            'action'      => 'install',
        ])->assertNotFound();
    }

    public function test_policy_detail_includes_live_compliance(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]); // drifted
        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['read']);

        $this->getJson("/api/v1/policies/{$policy->id}")
            ->assertOk()
            ->assertJsonPath('data.compliance.target', 1)
            ->assertJsonPath('data.compliance.non_compliant', 1);
    }

    public function test_deployment_filters_and_pagination_cap(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create();
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Succeeded,
        ]);
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Failed,
        ]);

        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['read']);

        $this->getJson('/api/v1/deployments?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed');

        // per_page is clamped to 100.
        $this->getJson('/api/v1/deployments?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }
}
