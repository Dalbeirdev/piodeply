<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\QueueOutcome;
use App\Enums\Role as RoleEnum;
use App\Livewire\Team\Approvals;
use App\Models\Client;
use App\Models\ClientRole;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\DeploymentRequest;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The approval workflow: an approval-gated role files requests instead of
 * jobs; the owner's decision queues or closes them — inside their own
 * tenant only.
 */
class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $owner;

    private User $requester;

    private Computer $computer;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->client = Client::factory()->create();
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['client_id' => $this->client->id])->save();
        $this->owner->assignRole(RoleEnum::ClientOwner->value);

        $project = Project::factory()->create(['client_id' => $this->client->id]);
        $this->computer = Computer::factory()->create(['project_id' => $project->id]);
        $this->package = Package::factory()->create();

        $role = ClientRole::factory()->create([
            'client_id'         => $this->client->id,
            'name'              => 'Requester',
            'can_install'       => true,
            'can_update'        => true,
            'requires_approval' => true,
        ]);

        $this->requester = User::factory()->create();
        $this->requester->forceFill([
            'client_id'      => $this->client->id,
            'client_role_id' => $role->id,
        ])->save();
        $this->requester->assignRole(RoleEnum::Technician->value);
    }

    public function test_an_approval_gated_deploy_files_a_request_not_a_job(): void
    {
        $this->actingAs($this->requester);

        $result = app(DeploymentService::class)->queueIfNeeded(
            $this->computer, $this->package, JobAction::Install
        );

        $this->assertSame(QueueOutcome::ApprovalRequested, $result->outcome);
        $this->assertStringContainsString('Sent for approval', $result->message);
        $this->assertSame(0, DeploymentJob::count());

        $request = DeploymentRequest::sole();
        $this->assertSame('pending', $request->status);
        $this->assertSame($this->requester->id, $request->requester_id);
        $this->assertSame($this->client->id, $request->client_id);
    }

    public function test_repeat_clicks_do_not_pile_up_duplicate_requests(): void
    {
        $this->actingAs($this->requester);
        $service = app(DeploymentService::class);

        $service->queueIfNeeded($this->computer, $this->package, JobAction::Install);
        $service->queueIfNeeded($this->computer, $this->package, JobAction::Install);

        $this->assertSame(1, DeploymentRequest::count());
    }

    public function test_approving_queues_the_job_and_stamps_the_request(): void
    {
        $this->actingAs($this->requester);
        app(DeploymentService::class)->queueIfNeeded($this->computer, $this->package, JobAction::Install);
        $request = DeploymentRequest::sole();

        Livewire::actingAs($this->owner)
            ->test(Approvals::class)
            ->call('approve', $request->id);

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($this->owner->id, $request->decided_by);
        $this->assertNotNull($request->job_id);
        $this->assertSame(1, DeploymentJob::count());
        $this->assertSame($this->computer->id, DeploymentJob::sole()->computer_id);
    }

    public function test_rejecting_closes_the_request_without_a_job(): void
    {
        $this->actingAs($this->requester);
        app(DeploymentService::class)->queueIfNeeded($this->computer, $this->package, JobAction::Install);
        $request = DeploymentRequest::sole();

        Livewire::actingAs($this->owner)
            ->test(Approvals::class)
            ->call('reject', $request->id);

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_a_foreign_owner_cannot_decide_this_tenants_requests(): void
    {
        $this->actingAs($this->requester);
        app(DeploymentService::class)->queueIfNeeded($this->computer, $this->package, JobAction::Install);
        $request = DeploymentRequest::sole();

        $foreignOwner = User::factory()->create();
        $foreignOwner->forceFill(['client_id' => Client::factory()->create()->id])->save();
        $foreignOwner->assignRole(RoleEnum::ClientOwner->value);

        try {
            Livewire::actingAs($foreignOwner)->test(Approvals::class)->call('approve', $request->id);
            $this->fail('a foreign owner must not reach this request');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_roles_without_the_gate_still_deploy_directly(): void
    {
        $direct = ClientRole::factory()->create([
            'client_id'   => $this->client->id,
            'can_install' => true,
        ]);
        $tech = User::factory()->create();
        $tech->forceFill(['client_id' => $this->client->id, 'client_role_id' => $direct->id])->save();
        $tech->assignRole(RoleEnum::Technician->value);

        $this->actingAs($tech);
        $result = app(DeploymentService::class)->queueIfNeeded(
            $this->computer, $this->package, JobAction::Install
        );

        $this->assertSame(QueueOutcome::Queued, $result->outcome);
        $this->assertSame(0, DeploymentRequest::count());
    }
}
