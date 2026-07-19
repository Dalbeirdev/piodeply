<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\BrowserPolicies\BrowserPolicyCompliance;
use App\Models\BrowserPolicy;
use App\Models\BrowserPolicyResult;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The cross-policy compliance dashboard: fleet totals, per-policy rows and
 * the failing-machines list, tenant-scoped.
 */
class BrowserPolicyComplianceTest extends TestCase
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

    /** A project with one policy, one compliant and one failing machine. */
    private function fixture(): array
    {
        $project = Project::factory()->create();
        $ok = Computer::factory()->create(['project_id' => $project->id, 'hostname' => 'OK-PC']);
        $bad = Computer::factory()->create(['project_id' => $project->id, 'hostname' => 'BAD-PC']);
        $policy = BrowserPolicy::factory()->create([
            'project_id' => $project->id, 'type' => 'disable_incognito', 'browsers' => ['chrome'],
        ]);

        BrowserPolicyResult::create([
            'browser_policy_id' => $policy->id, 'computer_id' => $ok->id,
            'browser' => 'chrome', 'status' => 'compliant', 'reported_at' => now(),
        ]);
        BrowserPolicyResult::create([
            'browser_policy_id' => $policy->id, 'computer_id' => $bad->id,
            'browser' => 'chrome', 'status' => 'non_compliant', 'detail' => 'registry write refused', 'reported_at' => now(),
        ]);

        return [$project, $policy, $ok, $bad];
    }

    public function test_dashboard_shows_policy_rows_and_failing_machines(): void
    {
        [, $policy] = $this->fixture();

        Livewire::actingAs($this->admin())
            ->test(BrowserPolicyCompliance::class)
            ->assertSee($policy->name)
            ->assertSee('BAD-PC')                    // attention list
            ->assertSee('registry write refused')    // the agent's own detail
            ->assertSee('Non-compliant');
    }

    public function test_only_problems_filter_hides_healthy_policies(): void
    {
        [, $policy] = $this->fixture();

        // A second, fully-healthy policy on its own project.
        $healthyProject = Project::factory()->create();
        $pc = Computer::factory()->create(['project_id' => $healthyProject->id]);
        $healthy = BrowserPolicy::factory()->create([
            'project_id' => $healthyProject->id, 'type' => 'disable_guest_mode',
            'browsers' => ['chrome'], 'name' => 'Healthy guests policy',
        ]);
        BrowserPolicyResult::create([
            'browser_policy_id' => $healthy->id, 'computer_id' => $pc->id,
            'browser' => 'chrome', 'status' => 'compliant', 'reported_at' => now(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(BrowserPolicyCompliance::class)
            ->set('onlyProblems', '1')
            ->assertSee($policy->name)
            ->assertDontSee('Healthy guests policy');
    }

    public function test_tenants_only_see_their_own_client(): void
    {
        [, $policy] = $this->fixture(); // belongs to some other client

        $client = Client::factory()->create();
        $tenantUser = User::factory()->create(['client_id' => $client->id]);
        $tenantUser->assignRole(RoleEnum::Client->value);

        Livewire::actingAs($tenantUser)
            ->test(BrowserPolicyCompliance::class)
            ->assertDontSee($policy->name)
            ->assertDontSee('BAD-PC');
    }

    public function test_page_is_reachable_and_not_swallowed_by_the_policy_binding(): void
    {
        $this->actingAs($this->admin())
            ->get('/browser-policies/compliance')
            ->assertOk()
            ->assertSee('Fleet protected');
    }
}
