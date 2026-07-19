<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Computers\ComputersIndex;
use App\Livewire\Dashboard;
use App\Models\Computer;
use App\Models\User;
use App\Services\EnrollmentScriptService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fleet-wide visibility of which machines run an outdated PioDeploy agent —
 * the model capability, the dashboard count and the Computers list filter.
 */
class AgentVersionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $latest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->latest = EnrollmentScriptService::CURRENT_AGENT_VERSION;
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    public function test_is_agent_outdated_helper(): void
    {
        $current = Computer::factory()->create(['agent_version' => $this->latest]);
        $old     = Computer::factory()->create(['agent_version' => '1.0.0']);
        $unknown = Computer::factory()->create(['agent_version' => null]);

        $this->assertFalse($current->isAgentOutdated());
        $this->assertTrue($old->isAgentOutdated());
        // Never reported → unknown, not counted as outdated.
        $this->assertFalse($unknown->isAgentOutdated());
    }

    public function test_agent_outdated_scope_selects_only_older_versions(): void
    {
        Computer::factory()->create(['agent_version' => $this->latest]);
        Computer::factory()->create(['agent_version' => '1.0.0']);
        Computer::factory()->create(['agent_version' => '1.2.9']);
        Computer::factory()->create(['agent_version' => null]);

        $this->assertSame(2, Computer::agentOutdated()->count());
    }

    public function test_dashboard_counts_outdated_agents(): void
    {
        Computer::factory()->create(['agent_version' => $this->latest]);
        Computer::factory()->create(['agent_version' => '1.0.0']);
        Computer::factory()->create(['agent_version' => '1.1.0']);

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('stats', fn (array $stats) => $stats['outdated_agents'] === 2
                && $stats['latest_agent'] === $this->latest);
    }

    public function test_computers_list_filters_to_outdated_agents(): void
    {
        Computer::factory()->create(['hostname' => 'CURRENT-PC', 'agent_version' => $this->latest]);
        Computer::factory()->create(['hostname' => 'STALE-PC', 'agent_version' => '1.0.0']);

        Livewire::actingAs($this->admin())
            ->test(ComputersIndex::class)
            ->set('agentStatus', 'outdated')
            ->assertSee('STALE-PC')
            ->assertDontSee('CURRENT-PC')
            ->assertSee('Update available');
    }

    public function test_computers_list_filters_to_current_agents(): void
    {
        Computer::factory()->create(['hostname' => 'CURRENT-PC', 'agent_version' => $this->latest]);
        Computer::factory()->create(['hostname' => 'STALE-PC', 'agent_version' => '1.0.0']);

        Livewire::actingAs($this->admin())
            ->test(ComputersIndex::class)
            ->set('agentStatus', 'current')
            ->assertSee('CURRENT-PC')
            ->assertDontSee('STALE-PC');
    }

    public function test_agent_status_is_bound_to_the_url_for_deep_linking(): void
    {
        // The dashboard card links to ?agentStatus=outdated; the property must
        // hydrate from the query string.
        Computer::factory()->create(['hostname' => 'STALE-PC', 'agent_version' => '1.0.0']);

        Livewire::withQueryParams(['agentStatus' => 'outdated'])
            ->actingAs($this->admin())
            ->test(ComputersIndex::class)
            ->assertSet('agentStatus', 'outdated')
            ->assertSee('STALE-PC');
    }
}
