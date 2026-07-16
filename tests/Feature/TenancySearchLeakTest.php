<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\BrowserPolicies\BrowserPoliciesIndex;
use App\Livewire\Deployments\DeploymentsIndex;
use App\Livewire\Policies\PoliciesIndex;
use App\Models\BrowserPolicy;
use App\Models\Client;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tenancy is applied with ->whereHas(...) and the search with
 * ->whereHas(...)->orWhereHas(...). Ungrouped, that is
 *
 *     (tenant AND a) OR b
 *
 * because AND binds tighter than OR — so the second search branch escapes
 * the tenant filter and a client-bound user reads another client's rows.
 * Each test below searches for a term that only matches via that second
 * branch.
 */
class TenancySearchLeakTest extends TestCase
{
    use RefreshDatabase;

    private Client $mine;

    private Client $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mine = Client::factory()->create(['company_name' => 'My Company']);
        $this->theirs = Client::factory()->create(['company_name' => 'Rival Ltd']);
    }

    /** A client's own login. Has DeploymentsView, but not PoliciesView. */
    private function clientUser(): User
    {
        return tap(
            User::factory()->create(['client_id' => $this->mine->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Client->value)
        );
    }

    /**
     * A manager scoped to one client. tenantClientId() keys off client_id
     * regardless of role, so this user is tenant-filtered like a client but
     * can reach the policy pages — which is where those leaks land.
     */
    private function clientBoundManager(): User
    {
        return tap(
            User::factory()->create(['client_id' => $this->mine->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Manager->value)
        );
    }

    private function projectFor(Client $client, string $name): Project
    {
        return Project::factory()->create(['client_id' => $client->id, 'name' => $name]);
    }

    public function test_searching_deployments_does_not_reveal_another_clients_machines(): void
    {
        $package = Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);

        $mineComputer = Computer::factory()->create([
            'project_id' => $this->projectFor($this->mine, 'Mine')->id, 'hostname' => 'MY-PC',
        ]);
        $theirComputer = Computer::factory()->create([
            'project_id' => $this->projectFor($this->theirs, 'Theirs')->id, 'hostname' => 'RIVAL-PC',
        ]);

        foreach ([$mineComputer, $theirComputer] as $computer) {
            DeploymentJob::factory()->create(['computer_id' => $computer->id, 'package_id' => $package->id]);
        }

        // The package name matches via the orWhereHas branch.
        Livewire::actingAs($this->clientUser())
            ->test(DeploymentsIndex::class)
            ->set('search', 'Google Chrome')
            ->assertSee('MY-PC')
            ->assertDontSee('RIVAL-PC')
            ->assertViewHas('jobs', fn ($jobs) => $jobs->total() === 1);
    }

    public function test_searching_policies_does_not_reveal_another_clients_policies(): void
    {
        $package = Package::factory()->create(['name' => 'Notepad++']);

        SoftwarePolicy::factory()->create([
            'project_id' => $this->projectFor($this->mine, 'Mine')->id,
            'package_id' => $package->id,
        ]);
        SoftwarePolicy::factory()->create([
            'project_id' => $this->projectFor($this->theirs, 'Rival Rollout')->id,
            'package_id' => $package->id,
        ]);

        // "Rival Rollout" is the other tenant's PROJECT name — it matches only
        // via the orWhereHas branch, which must still be inside the tenancy.
        Livewire::actingAs($this->clientBoundManager())
            ->test(PoliciesIndex::class)
            ->set('search', 'Rival Rollout')
            ->assertDontSee('Rival Rollout')
            ->assertViewHas('policies', fn ($policies) => $policies->total() === 0);
    }

    public function test_searching_browser_policies_does_not_reveal_another_clients_policies(): void
    {
        BrowserPolicy::factory()->create([
            'project_id' => $this->projectFor($this->mine, 'Mine')->id,
            'name'       => 'My policy',
        ]);
        BrowserPolicy::factory()->create([
            'project_id' => $this->projectFor($this->theirs, 'Rival Rollout')->id,
            'name'       => 'Rival secret policy',
        ]);

        Livewire::actingAs($this->clientBoundManager())
            ->test(BrowserPoliciesIndex::class)
            ->set('search', 'Rival Rollout')
            ->assertDontSee('Rival secret policy')
            ->assertViewHas('policies', fn ($policies) => $policies->total() === 0);
    }

    /** The tenancy fix must not break the search itself. */
    public function test_search_still_finds_the_users_own_rows_by_either_branch(): void
    {
        $package = Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);
        $computer = Computer::factory()->create([
            'project_id' => $this->projectFor($this->mine, 'Mine')->id, 'hostname' => 'MY-PC',
        ]);
        DeploymentJob::factory()->create(['computer_id' => $computer->id, 'package_id' => $package->id]);

        $user = $this->clientUser();

        // by hostname (first branch)
        Livewire::actingAs($user)->test(DeploymentsIndex::class)
            ->set('search', 'MY-PC')
            ->assertViewHas('jobs', fn ($jobs) => $jobs->total() === 1);

        // by package name (second branch)
        Livewire::actingAs($user)->test(DeploymentsIndex::class)
            ->set('search', 'Google Chrome')
            ->assertViewHas('jobs', fn ($jobs) => $jobs->total() === 1);
    }
}
