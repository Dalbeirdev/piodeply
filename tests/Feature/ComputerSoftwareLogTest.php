<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\PolicyAction;
use App\Enums\PolicyMode;
use App\Enums\PolicyVersionMode;
use App\Enums\Role as RoleEnum;
use App\Livewire\Computers\ComputerShow;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\SoftwarePolicy;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Why isn't it installed?" has two answers, in two places: a job that
 * failed, or no job at all because a policy decided against one. The page
 * has to give both.
 */
class ComputerSoftwareLogTest extends TestCase
{
    use RefreshDatabase;

    private Computer $computer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->computer = Computer::factory()->create();
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    private function page()
    {
        return Livewire::actingAs($this->admin())
            ->test(ComputerShow::class, ['computer' => $this->computer]);
    }

    private function policy(Package $package, array $attributes = []): SoftwarePolicy
    {
        return SoftwarePolicy::factory()->create([
            'project_id' => $this->computer->project_id,
            'package_id' => $package->id,
            'action'     => PolicyAction::Install,
            'mode'       => PolicyMode::Enforce,
            ...$attributes,
        ]);
    }

    private function chrome(): Package
    {
        return Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);
    }

    /* ─────────── why nothing happened ─────────── */

    public function test_it_says_why_a_package_is_considered_compliant(): void
    {
        $this->policy($this->chrome());
        ComputerSoftware::factory()->create([
            'computer_id' => $this->computer->id, 'name' => 'Google.Chrome',
            'version' => '141.0', 'source' => 'winget',
        ]);

        $this->page()
            ->assertSee('Software status')
            ->assertSee('Installed (141.0)');
    }

    public function test_it_names_the_version_gap_rather_than_just_saying_non_compliant(): void
    {
        $this->policy($this->chrome(), [
            'version_mode' => PolicyVersionMode::Minimum, 'desired_version' => '141.0',
        ]);
        ComputerSoftware::factory()->create([
            'computer_id' => $this->computer->id, 'name' => 'Google.Chrome',
            'version' => '138.0', 'source' => 'winget',
        ]);

        $this->page()->assertSee('Installed 138.0, policy wants');
    }

    public function test_a_disabled_policy_explains_that_nothing_will_run(): void
    {
        $this->policy($this->chrome(), ['mode' => PolicyMode::Disabled]);

        $this->page()->assertSee('Policy is disabled');
    }

    public function test_an_inactive_package_explains_that_nothing_will_run(): void
    {
        $this->policy(Package::factory()->create(['winget_id' => 'Dead.App', 'is_active' => false]));

        $this->page()->assertSee('Package is not active in the catalogue');
    }

    public function test_with_no_policies_it_says_so_plainly(): void
    {
        $this->page()->assertSee('No software policies target this machine');
    }

    /* ─────────── why a job ended how it did ─────────── */

    public function test_a_failed_job_shows_the_agents_reason_and_output(): void
    {
        DeploymentJob::factory()->create([
            'computer_id'    => $this->computer->id,
            'package_id'     => $this->chrome()->id,
            'status'         => JobStatus::Failed,
            'action'         => JobAction::Install,
            'failure_reason' => 'winget exited with 1',
            'exit_code'      => 1,
            'output_log'     => 'Installer hash mismatch',
        ]);

        $this->page()
            ->assertSee('Deployment log')
            ->assertSee('winget exited with 1')
            ->assertSee('Installer hash mismatch')
            ->assertSee('exit 1');
    }

    /** The 29 "successful" installs that installed nothing. */
    public function test_an_already_installed_exit_code_is_not_passed_off_as_an_install(): void
    {
        DeploymentJob::factory()->create([
            'computer_id' => $this->computer->id,
            'package_id'  => $this->chrome()->id,
            'status'      => JobStatus::Succeeded,
            'action'      => JobAction::Install,
            'exit_code'   => -1978335189, // winget: already installed
        ]);

        $this->page()->assertSee('Already installed — nothing was changed');
    }

    public function test_no_applicable_upgrade_reads_as_already_current(): void
    {
        DeploymentJob::factory()->create([
            'computer_id' => $this->computer->id,
            'package_id'  => $this->chrome()->id,
            'status'      => JobStatus::Succeeded,
            'action'      => JobAction::Update,
            'exit_code'   => -1978335188, // winget: no applicable upgrade
        ]);

        $this->page()->assertSee('Already up to date — no newer version offered');
    }

    public function test_a_real_install_reports_what_it_landed_on(): void
    {
        $job = DeploymentJob::factory()->make([
            'status'                   => JobStatus::Succeeded,
            'action'                   => JobAction::Install,
            'exit_code'                => 0,
            'installed_version_after'  => '141.0',
        ]);

        $this->assertSame('Completed — now on 141.0', $job->reasonLabel());
    }

    public function test_a_queued_job_says_what_it_is_waiting_for(): void
    {
        $job = DeploymentJob::factory()->make([
            'status' => JobStatus::Pending, 'attempts' => 0,
        ]);

        $this->assertSame('Queued — waiting for the agent to check in', $job->reasonLabel());
    }

    public function test_a_retry_carries_the_failure_that_caused_it(): void
    {
        $job = DeploymentJob::factory()->make([
            'status' => JobStatus::Pending, 'attempts' => 1, 'max_attempts' => 3,
            'failure_reason' => 'network timeout',
        ]);

        $this->assertSame('Retrying after: network timeout (attempt 1 of 3)', $job->reasonLabel());
    }

    public function test_a_failure_with_no_reason_admits_it_rather_than_inventing_one(): void
    {
        $job = DeploymentJob::factory()->make([
            'status' => JobStatus::Failed, 'failure_reason' => null,
        ]);

        $this->assertSame('Failed without reporting a reason', $job->reasonLabel());
    }

    public function test_a_blocked_job_names_the_job_it_waits_on(): void
    {
        $job = DeploymentJob::factory()->make([
            'status' => JobStatus::Blocked, 'depends_on_job_id' => 42,
        ]);

        $this->assertSame('Waiting on job #42 to succeed', $job->reasonLabel());
    }

    public function test_the_log_is_not_visible_to_someone_who_cannot_see_the_computer(): void
    {
        $outsider = tap(
            User::factory()->create(['client_id' => \App\Models\Client::factory()->create()->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Client->value)
        );

        Livewire::actingAs($outsider)
            ->test(ComputerShow::class, ['computer' => $this->computer])
            ->assertForbidden();
    }
}
