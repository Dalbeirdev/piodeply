<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\Role as RoleEnum;
use App\Livewire\Deployments\DeploymentsIndex;
use App\Livewire\Deployments\DeployToComputer;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\User;
use App\Services\DeploymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeploymentEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function service(): DeploymentService
    {
        return app(DeploymentService::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    public function test_queue_creates_pending_job(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create();

        $job = $this->service()->queue($computer, $package, JobAction::Install, priority: 3);

        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertSame(3, $job->priority);
        $this->assertSame(1, $this->service()->pendingCountFor($computer));
    }

    public function test_claim_returns_jobs_in_priority_then_fifo_order(): void
    {
        $computer = Computer::factory()->create();
        $p = Package::factory()->create();

        $low = $this->service()->queue($computer, $p, JobAction::Install, priority: 8);
        $high = $this->service()->queue($computer, $p, JobAction::Install, priority: 1);
        $mid1 = $this->service()->queue($computer, $p, JobAction::Install, priority: 5);
        $mid2 = $this->service()->queue($computer, $p, JobAction::Install, priority: 5);

        $claimed = $this->service()->claimFor($computer);

        $this->assertSame(
            [$high->id, $mid1->id, $mid2->id, $low->id],
            $claimed->pluck('id')->all()
        );
        $claimed->each(fn ($job) => $this->assertSame(JobStatus::Running, $job->status));
        $this->assertSame(1, $claimed->first()->attempts);
    }

    public function test_claim_does_not_return_another_computers_jobs(): void
    {
        $mine = Computer::factory()->create();
        $other = Computer::factory()->create();
        $p = Package::factory()->create();
        $this->service()->queue($other, $p, JobAction::Install);

        $this->assertCount(0, $this->service()->claimFor($mine));
    }

    public function test_failed_job_with_retries_returns_to_pending(): void
    {
        $job = DeploymentJob::factory()->running()->create(['max_attempts' => 3, 'attempts' => 1]);

        $result = $this->service()->reportResult($job, success: false, exitCode: 1, log: 'boom', failureReason: 'installer error');

        $this->assertSame(JobStatus::Pending, $result->status);
        $this->assertSame('installer error', $result->failure_reason);
        $this->assertNull($result->claimed_at);
    }

    public function test_failed_job_out_of_retries_is_terminal(): void
    {
        $job = DeploymentJob::factory()->running()->create(['max_attempts' => 3, 'attempts' => 3]);

        $result = $this->service()->reportResult($job, success: false, exitCode: 1, log: 'boom', failureReason: 'gave up');

        $this->assertSame(JobStatus::Failed, $result->status);
        $this->assertNotNull($result->finished_at);
    }

    public function test_success_records_exit_code_and_log(): void
    {
        $job = DeploymentJob::factory()->running()->create();

        $result = $this->service()->reportResult($job, success: true, exitCode: 0, log: 'Installed OK');

        $this->assertSame(JobStatus::Succeeded, $result->status);
        $this->assertSame(0, $result->exit_code);
        $this->assertSame('Installed OK', $result->output_log);
    }

    public function test_dependent_job_is_blocked_then_released_on_success(): void
    {
        $computer = Computer::factory()->create();
        $runtime = Package::factory()->create();
        $app = Package::factory()->create();

        $dep = $this->service()->queue($computer, $runtime, JobAction::Install, priority: 1);
        $dependent = $this->service()->queue($computer, $app, JobAction::Install, dependsOn: $dep);

        $this->assertSame(JobStatus::Blocked, $dependent->status);
        // A blocked job is not claimable.
        $this->assertSame([$dep->id], $this->service()->claimFor($computer)->pluck('id')->all());

        $this->service()->reportResult($dep->fresh(), success: true, exitCode: 0, log: null);

        $this->assertSame(JobStatus::Pending, $dependent->fresh()->status);
    }

    public function test_dependent_job_is_cancelled_when_dependency_permanently_fails(): void
    {
        $computer = Computer::factory()->create();
        $dep = DeploymentJob::factory()->for($computer)->running()->create(['attempts' => 3, 'max_attempts' => 3]);
        $dependent = $this->service()->queue($computer, Package::factory()->create(), JobAction::Install, dependsOn: $dep);

        $this->assertSame(JobStatus::Blocked, $dependent->status);

        $this->service()->reportResult($dep, success: false, exitCode: 1, log: null, failureReason: 'dead');

        $this->assertSame(JobStatus::Cancelled, $dependent->fresh()->status);
    }

    public function test_manual_retry_resets_attempts(): void
    {
        $job = DeploymentJob::factory()->failed()->create();

        $result = $this->service()->retry($job);

        $this->assertSame(JobStatus::Pending, $result->status);
        $this->assertSame(0, $result->attempts);
    }

    /* ---- Agent API ---- */

    public function test_agent_heartbeat_reports_pending_job_count(): void
    {
        [$computer, $headers, $uuid] = $this->registeredAgent();
        $this->service()->queue($computer, Package::factory()->create(), JobAction::Install);
        $this->service()->queue($computer, Package::factory()->create(), JobAction::Install);

        $this->postJson('/api/v1/agent/heartbeat', ['agent_uuid' => $uuid], $headers)
            ->assertOk()
            ->assertJsonPath('pending_jobs', 2);
    }

    public function test_agent_claims_jobs_and_reports_result(): void
    {
        [$computer, $headers, $uuid] = $this->registeredAgent();
        $package = Package::factory()->create(['winget_id' => 'Google.Chrome']);
        $job = $this->service()->queue($computer, $package, JobAction::Install);

        $response = $this->postJson('/api/v1/agent/jobs', ['agent_uuid' => $uuid], $headers)->assertOk();
        $response->assertJsonPath('jobs.0.job_id', $job->id)
            ->assertJsonPath('jobs.0.action', 'install')
            ->assertJsonPath('jobs.0.winget_id', 'Google.Chrome');

        $this->assertSame(JobStatus::Running, $job->fresh()->status);

        $this->postJson("/api/v1/agent/jobs/{$job->id}/result", [
            'agent_uuid' => $uuid,
            'success'    => true,
            'exit_code'  => 0,
            'output_log' => 'winget install succeeded',
        ], $headers)->assertOk()->assertJsonPath('status', 'succeeded');

        $this->assertSame(JobStatus::Succeeded, $job->fresh()->status);
    }

    public function test_agent_cannot_report_on_another_computers_job(): void
    {
        [, $headers, $uuid] = $this->registeredAgent();
        $foreignJob = DeploymentJob::factory()->running()->create();

        $this->postJson("/api/v1/agent/jobs/{$foreignJob->id}/result", [
            'agent_uuid' => $uuid,
            'success'    => true,
        ], $headers)->assertNotFound();
    }

    /* ---- Portal UI ---- */

    public function test_deploy_widget_queues_a_job(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(DeployToComputer::class, ['computer' => $computer])
            ->set('package_id', $package->id)
            ->set('action', 'install')
            ->set('priority', 2)
            ->call('queue')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $computer->id,
            'package_id'  => $package->id,
            'action'      => 'install',
            'priority'    => 2,
        ]);
    }

    public function test_technician_can_deploy_but_viewer_cannot(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create();

        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));
        Livewire::actingAs($viewer)
            ->test(DeployToComputer::class, ['computer' => $computer])
            ->set('package_id', $package->id)
            ->call('queue')
            ->assertForbidden();

        $technician = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Technician->value));
        Livewire::actingAs($technician)
            ->test(DeployToComputer::class, ['computer' => $computer])
            ->set('package_id', $package->id)
            ->call('queue')
            ->assertHasNoErrors();
    }

    public function test_index_retry_and_cancel(): void
    {
        $failed = DeploymentJob::factory()->failed()->create();
        $pending = DeploymentJob::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->call('retry', $failed->id)
            ->call('cancel', $pending->id);

        $this->assertSame(JobStatus::Pending, $failed->fresh()->status);
        $this->assertSame(JobStatus::Cancelled, $pending->fresh()->status);
    }

    public function test_deployments_menu_visible_to_permitted_users(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertSee('Deployments');
    }

    /**
     * @return array{0: Computer, 1: array<string,string>, 2: string}
     */
    private function registeredAgent(): array
    {
        $result = app(\App\Services\ProjectService::class)->create(new \App\DTOs\ProjectData(
            clientId: \App\Models\Client::factory()->create()->id,
            name: 'Deploy Fleet',
        ));
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $computer = Computer::factory()->for($result['project'])->create(['agent_uuid' => $uuid]);

        return [$computer, ['X-Api-Key' => $result['plain_api_key'], 'Accept' => 'application/json'], $uuid];
    }
}
