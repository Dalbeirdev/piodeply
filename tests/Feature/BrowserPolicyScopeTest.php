<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\BrowserPolicies\BrowserPolicyForm;
use App\Models\BrowserPolicy;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerGroup;
use App\Models\Project;
use App\Models\User;
use App\Services\BrowserPolicyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The assignment hierarchy: scopes (all/client/project/group/computer),
 * specificity-based inheritance, legacy defaulting, and tenant visibility.
 */
class BrowserPolicyScopeTest extends TestCase
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

    private function scoped(string $type, string $scopeType, int $scopeId, array $extra = []): BrowserPolicy
    {
        return BrowserPolicy::factory()->create([
            'project_id' => null,
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'type'       => $type,
            'name'       => "{$scopeType} {$type}",
        ] + $extra);
    }

    public function test_legacy_project_writers_get_a_scope_automatically(): void
    {
        // The factory (like the v1 API and templates) sets only project_id.
        $policy = BrowserPolicy::factory()->create();

        $this->assertSame('project', $policy->scope_type);
        $this->assertSame($policy->project_id, $policy->scope_id);
    }

    public function test_the_most_specific_scope_wins_per_type(): void
    {
        $computer = Computer::factory()->create();
        $project = $computer->project;
        $clientId = $project->client_id;
        $group = ComputerGroup::factory()->create();
        $group->computers()->attach($computer);

        $all = $this->scoped('disable_incognito', 'all', 0);
        $client = $this->scoped('disable_incognito', 'client', $clientId);
        $projectPolicy = BrowserPolicy::factory()->create(['project_id' => $project->id, 'type' => 'disable_incognito', 'name' => 'project rule']);
        $groupPolicy = $this->scoped('disable_incognito', 'group', $group->id);
        $device = $this->scoped('disable_incognito', 'computer', $computer->id);

        // A second type at a broad scope must survive alongside.
        $guestAll = $this->scoped('disable_guest_mode', 'all', 0);

        $resolved = BrowserPolicy::resolveFor($computer);
        $byType = $resolved->keyBy(fn (BrowserPolicy $p) => $p->type->value);

        $this->assertSame($device->id, $byType['disable_incognito']->id);   // computer beats everything
        $this->assertSame($guestAll->id, $byType['disable_guest_mode']->id); // untouched type inherits from 'all'
        $this->assertCount(2, $resolved);

        // Remove layers one by one: the next specificity takes over.
        $device->delete();
        $this->assertSame($groupPolicy->id, BrowserPolicy::resolveFor($computer)->keyBy(fn ($p) => $p->type->value)['disable_incognito']->id);

        $groupPolicy->delete();
        $this->assertSame($projectPolicy->id, BrowserPolicy::resolveFor($computer)->keyBy(fn ($p) => $p->type->value)['disable_incognito']->id);

        $projectPolicy->delete();
        $this->assertSame($client->id, BrowserPolicy::resolveFor($computer)->keyBy(fn ($p) => $p->type->value)['disable_incognito']->id);

        $client->delete();
        $this->assertSame($all->id, BrowserPolicy::resolveFor($computer)->keyBy(fn ($p) => $p->type->value)['disable_incognito']->id);
    }

    public function test_group_scope_reaches_members_only_and_exclusions_still_win(): void
    {
        $member = Computer::factory()->create();
        $outsider = Computer::factory()->create();
        $group = ComputerGroup::factory()->create();
        $group->computers()->attach($member);

        $policy = $this->scoped('disable_downloads', 'group', $group->id);

        $service = app(BrowserPolicyService::class);
        $this->assertSame([$policy->id], collect($service->documentFor($member)['policies'])->pluck('policy_id')->all());
        $this->assertSame([], $service->documentFor($outsider)['policies']);

        // An excluded machine drops the policy even at high specificity.
        $policy->excludedComputers()->attach($member);
        $this->assertSame([], $service->documentFor($member)['policies']);
    }

    public function test_all_scope_reaches_every_machine(): void
    {
        $a = Computer::factory()->create();
        $b = Computer::factory()->create();
        $policy = $this->scoped('disable_popups', 'all', 0);

        $service = app(BrowserPolicyService::class);
        $this->assertSame([$policy->id], collect($service->documentFor($a)['policies'])->pluck('policy_id')->all());
        $this->assertSame([$policy->id], collect($service->documentFor($b)['policies'])->pluck('policy_id')->all());
        $this->assertSame(2, $policy->targetComputers()->count());
    }

    public function test_same_scope_and_type_is_a_conflict_but_across_scopes_is_not(): void
    {
        $project = Project::factory()->create();
        BrowserPolicy::factory()->create(['project_id' => $project->id, 'type' => 'disable_incognito']);

        // Same project + type → refused.
        Livewire::actingAs($this->admin())
            ->test(BrowserPolicyForm::class)
            ->set('name', 'Duplicate')
            ->set('scope_type', 'project')
            ->set('scope_id', $project->id)
            ->set('type', 'disable_incognito')
            ->call('save')
            ->assertHasErrors('type');

        // Same type at 'all' scope → allowed (specificity resolves overlap).
        Livewire::actingAs($this->admin())
            ->test(BrowserPolicyForm::class)
            ->set('name', 'Instance-wide incognito ban')
            ->set('scope_type', 'all')
            ->set('type', 'disable_incognito')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('browser_policies', ['scope_type' => 'all', 'scope_id' => 0, 'type' => 'disable_incognito']);
    }

    public function test_tenants_see_shared_policies_but_compliance_counts_their_machines_only(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        Computer::factory()->create(['project_id' => $project->id]);
        Computer::factory()->create(); // foreign machine on another client

        $shared = $this->scoped('disable_popups', 'all', 0);
        $foreign = BrowserPolicy::factory()->create(['name' => 'Foreign project rule']); // other client's project

        $tenant = tap(User::factory()->create(['client_id' => $client->id]), fn (User $u) => $u->assignRole(RoleEnum::Client->value));

        // Visibility: shared yes, foreign no.
        $visible = BrowserPolicy::visibleTo($client->id)->pluck('id');
        $this->assertTrue($visible->contains($shared->id));
        $this->assertFalse($visible->contains($foreign->id));

        // Through the tenant lens the shared policy counts one machine, not two.
        $summary = app(BrowserPolicyService::class)->complianceSummary($shared, $client->id);
        $this->assertSame(1, $summary['target']);
        $this->assertSame(2, app(BrowserPolicyService::class)->complianceSummary($shared)['target']);
    }
}
