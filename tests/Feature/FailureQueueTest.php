<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\Role as RoleEnum;
use App\Livewire\Deployments\FailureQueue;
use App\Models\Client;
use App\Models\Computer;
use App\Models\DeploymentFailureDismissal;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\FailureQueueService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Needs attention" queue: what is still broken, grouped by cause the
 * same way escalate() groups it, gone the moment a fix actually works, and
 * dismissible by hand only by whoever the cause actually belongs to.
 */
class FailureQueueTest extends TestCase
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

    private function owner(Client $client): User
    {
        $user = User::factory()->create();
        $user->forceFill(['client_id' => $client->id])->save();
        $user->assignRole(RoleEnum::ClientOwner->value);

        return $user;
    }

    private function computerFor(Client $client): Computer
    {
        $project = Project::factory()->create(['client_id' => $client->id]);

        return Computer::factory()->create(['project_id' => $project->id]);
    }

    private function service(): FailureQueueService
    {
        return app(FailureQueueService::class);
    }

    public function test_a_package_that_fails_everywhere_is_one_cause_not_many(): void
    {
        $client = Client::factory()->create();
        $package = Package::factory()->create();

        foreach (range(1, 3) as $i) {
            DeploymentJob::factory()->failed()->create([
                'package_id'  => $package->id,
                'computer_id' => $this->computerFor($client)->id,
                'exit_code'   => -1978335212,
            ]);
        }

        $causes = $this->service()->unresolvedCauses($this->admin());

        $this->assertCount(1, $causes);
        $this->assertSame(3, $causes->first()['affected_computers']);
    }

    public function test_a_machine_problem_is_its_own_cause_per_machine(): void
    {
        $client = Client::factory()->create();
        $package = Package::factory()->create();

        foreach (range(1, 2) as $i) {
            DeploymentJob::factory()->failed()->create([
                'package_id'  => $package->id,
                'computer_id' => $this->computerFor($client)->id,
                'exit_code'   => 112, // disk full
            ]);
        }

        $causes = $this->service()->unresolvedCauses($this->admin());

        $this->assertCount(2, $causes);
    }

    public function test_a_later_success_for_the_same_package_clears_the_cause(): void
    {
        $client = Client::factory()->create();
        $package = Package::factory()->create();

        DeploymentJob::factory()->failed()->create([
            'package_id' => $package->id, 'computer_id' => $this->computerFor($client)->id,
            'exit_code' => -1978335212, 'finished_at' => now()->subHour(),
        ]);

        $this->assertCount(1, $this->service()->unresolvedCauses($this->admin()));

        // A DIFFERENT machine of the same package succeeding afterwards is
        // enough — the package-level cause is about the package, not one box.
        DeploymentJob::factory()->succeeded()->create([
            'package_id' => $package->id, 'computer_id' => $this->computerFor($client)->id,
            'finished_at' => now(),
        ]);

        $this->assertCount(0, $this->service()->unresolvedCauses($this->admin()));
    }

    public function test_a_later_success_on_the_same_machine_clears_a_machine_cause(): void
    {
        $client = Client::factory()->create();
        $computer = $this->computerFor($client);

        DeploymentJob::factory()->failed()->create([
            'computer_id' => $computer->id, 'exit_code' => 112, 'finished_at' => now()->subHour(),
        ]);
        $this->assertCount(1, $this->service()->unresolvedCauses($this->admin()));

        // A different PACKAGE on the same machine succeeding is enough — the
        // machine can accept installs again, which is the whole cause.
        DeploymentJob::factory()->succeeded()->create(['computer_id' => $computer->id, 'finished_at' => now()]);

        $this->assertCount(0, $this->service()->unresolvedCauses($this->admin()));
    }

    public function test_dismissing_hides_it_but_a_fresh_failure_brings_it_back(): void
    {
        $client = Client::factory()->create();
        $package = Package::factory()->create();
        $computer = $this->computerFor($client);

        $first = DeploymentJob::factory()->failed()->create([
            'package_id' => $package->id, 'computer_id' => $computer->id, 'exit_code' => 112,
        ]);

        $this->service()->markHandled($first->causeKey(), $first, $this->owner($client));
        $this->assertCount(0, $this->service()->unresolvedCauses($this->admin()));

        // The SAME cause fails again, on a fresh job with a higher id.
        $second = DeploymentJob::factory()->failed()->create([
            'package_id' => $package->id, 'computer_id' => $computer->id, 'exit_code' => 112,
        ]);
        $this->assertGreaterThan($first->id, $second->id);

        $this->assertCount(1, $this->service()->unresolvedCauses($this->admin()), 'a fresh failure must not stay hidden');
    }

    public function test_a_client_owner_only_sees_causes_on_their_own_fleet(): void
    {
        $mine = Client::factory()->create();
        $theirs = Client::factory()->create();

        DeploymentJob::factory()->failed()->create(['computer_id' => $this->computerFor($mine)->id, 'exit_code' => 112]);
        DeploymentJob::factory()->failed()->create(['computer_id' => $this->computerFor($theirs)->id, 'exit_code' => 112]);

        $causes = $this->service()->unresolvedCauses($this->owner($mine));

        $this->assertCount(1, $causes);
    }

    public function test_a_client_owner_may_dismiss_a_machine_cause_on_their_own_computer(): void
    {
        $client = Client::factory()->create();
        $job = DeploymentJob::factory()->failed()->create([
            'computer_id' => $this->computerFor($client)->id, 'exit_code' => 112,
        ]);

        $this->service()->markHandled($job->causeKey(), $job, $this->owner($client));

        $this->assertDatabaseHas('deployment_failure_dismissals', ['cause_key' => $job->causeKey()]);
    }

    public function test_a_client_owner_cannot_dismiss_another_clients_machine_cause(): void
    {
        $theirs = Client::factory()->create();
        $mine = Client::factory()->create();
        $job = DeploymentJob::factory()->failed()->create([
            'computer_id' => $this->computerFor($theirs)->id, 'exit_code' => 112,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service()->markHandled($job->causeKey(), $job, $this->owner($mine));
    }

    /**
     * The important one: a package cause can affect several tenants, so only
     * platform staff may silence it — a client dismissing it would hide it
     * from everyone it affects, not just themselves.
     */
    public function test_a_client_owner_cannot_dismiss_a_package_level_cause(): void
    {
        $client = Client::factory()->create();
        $job = DeploymentJob::factory()->failed()->create([
            'computer_id' => $this->computerFor($client)->id, 'exit_code' => -1978335212,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service()->markHandled($job->causeKey(), $job, $this->owner($client));
    }

    public function test_staff_may_dismiss_a_package_level_cause(): void
    {
        $client = Client::factory()->create();
        $job = DeploymentJob::factory()->failed()->create([
            'computer_id' => $this->computerFor($client)->id, 'exit_code' => -1978335212,
        ]);

        $this->service()->markHandled($job->causeKey(), $job, $this->admin());

        $this->assertDatabaseHas('deployment_failure_dismissals', ['cause_key' => $job->causeKey()]);
    }

    public function test_the_page_renders_and_dismiss_works_end_to_end(): void
    {
        $client = Client::factory()->create();
        $job = DeploymentJob::factory()->failed()->create([
            'computer_id' => $this->computerFor($client)->id, 'exit_code' => 112, 'failure_reason' => 'winget exited with 112.',
        ]);

        // Not asserting on "Needs attention" — that text lives in the
        // x-slot="header", which Livewire renders outside this component's
        // own DOM, so it never appears in a Livewire test's captured HTML.
        Livewire::actingAs($this->admin())
            ->test(FailureQueue::class)
            ->assertOk()
            ->assertSee('112')
            ->call('dismiss', $job->causeKey(), $job->id)
            ->assertOk();

        $this->assertDatabaseHas('deployment_failure_dismissals', ['cause_key' => $job->causeKey()]);
    }

    public function test_a_viewer_without_manage_cannot_dismiss(): void
    {
        $client = Client::factory()->create();
        $job = DeploymentJob::factory()->failed()->create([
            'computer_id' => $this->computerFor($client)->id, 'exit_code' => 112,
        ]);
        $technician = tap(User::factory()->create(), function (User $u) use ($client) {
            $u->forceFill(['client_id' => $client->id])->save();
        });
        $technician->assignRole(RoleEnum::Viewer->value);

        Livewire::actingAs($technician)
            ->test(FailureQueue::class)
            ->call('dismiss', $job->causeKey(), $job->id)
            ->assertForbidden();
    }

    public function test_only_terminal_failures_appear_not_ones_still_being_retried(): void
    {
        $client = Client::factory()->create();
        DeploymentJob::factory()->create([
            'status'      => JobStatus::Pending,
            'computer_id' => $this->computerFor($client)->id,
            'exit_code'   => 1618, // transient — back in the queue, not terminal
        ]);

        $this->assertCount(0, $this->service()->unresolvedCauses($this->admin()));
    }

    public function test_an_empty_queue_reports_zero_not_an_error(): void
    {
        $this->assertCount(0, $this->service()->unresolvedCauses($this->admin()));
    }
}
