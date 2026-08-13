<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\Role as RoleEnum;
use App\Livewire\Team\ClientRoles;
use App\Livewire\Team\TeamIndex;
use App\Models\Client;
use App\Models\ClientRole;
use App\Models\Computer;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Custom client roles: an owner defines WHAT (install/update/uninstall)
 * and WHERE (all machines or an explicit list); holders are narrowed at
 * the deployment funnel and in machine visibility, inside their own
 * tenant only.
 */
class ClientRolesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $owner;

    private Project $project;

    private Computer $granted;

    private Computer $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->client = Client::factory()->create();
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['client_id' => $this->client->id])->save();
        $this->owner->assignRole(RoleEnum::ClientOwner->value);

        $this->project = Project::factory()->create(['client_id' => $this->client->id]);
        $this->granted = Computer::factory()->create(['project_id' => $this->project->id]);
        $this->other = Computer::factory()->create(['project_id' => $this->project->id]);
    }

    private function updaterOfOneMachine(): ClientRole
    {
        $role = ClientRole::factory()->create([
            'client_id'     => $this->client->id,
            'name'          => 'Updater - one machine',
            'can_install'   => false,
            'can_update'    => true,
            'can_uninstall' => false,
            'scope'         => 'computers',
        ]);
        $role->computers()->attach($this->granted);

        return $role;
    }

    private function holderOf(ClientRole $role): User
    {
        $holder = User::factory()->create();
        $holder->forceFill([
            'client_id'      => $this->client->id,
            'client_role_id' => $role->id,
        ])->save();
        $holder->assignRole(RoleEnum::Technician->value);

        return $holder;
    }

    public function test_an_owner_creates_a_role_scoped_to_selected_machines(): void
    {
        Livewire::actingAs($this->owner)
            ->test(ClientRoles::class)
            ->dispatch('client-roles-new')
            ->set('name', 'Front desk updater')
            ->set('can_install', false)
            ->set('can_update', true)
            ->set('scope', 'computers')
            ->set('computerIds', [$this->granted->id])
            ->call('save')
            ->assertHasNoErrors();

        $role = ClientRole::where('name', 'Front desk updater')->sole();
        $this->assertSame($this->client->id, $role->client_id);
        $this->assertSame([$this->granted->id], $role->computers()->pluck('computers.id')->all());
    }

    public function test_foreign_machines_never_enter_a_role(): void
    {
        $foreign = Computer::factory()->create(); // another tenant entirely

        Livewire::actingAs($this->owner)
            ->test(ClientRoles::class)
            ->dispatch('client-roles-new')
            ->set('name', 'Sneaky')
            ->set('scope', 'computers')
            ->set('computerIds', [$this->granted->id, $foreign->id])
            ->call('save');

        $role = ClientRole::where('name', 'Sneaky')->sole();
        $this->assertSame([$this->granted->id], $role->computers()->pluck('computers.id')->all());
    }

    public function test_only_owners_manage_roles(): void
    {
        $tech = User::factory()->create();
        $tech->forceFill(['client_id' => $this->client->id])->save();
        $tech->assignRole(RoleEnum::Technician->value);

        $this->actingAs($tech)->get(route('team.roles'))->assertForbidden();
    }

    public function test_a_member_can_be_created_with_a_custom_role(): void
    {
        $role = $this->updaterOfOneMachine();

        Livewire::actingAs($this->owner)
            ->test(TeamIndex::class)
            ->set('newName', 'Desk Person')
            ->set('newEmail', 'desk@client.example')
            ->set('newPassword', 'Desk-password-99')
            ->set('newRole', 'custom:'.$role->id)
            ->call('create')
            ->assertHasNoErrors();

        $member = User::where('email', 'desk@client.example')->sole();
        $this->assertSame($role->id, $member->client_role_id);
        $this->assertTrue($member->hasRole(RoleEnum::Technician->value), 'baseline ladder role for page access');
    }

    public function test_the_overlay_narrows_actions_and_machines_at_the_funnel(): void
    {
        $holder = $this->holderOf($this->updaterOfOneMachine());
        $package = Package::factory()->create();
        $service = app(DeploymentService::class);

        $this->actingAs($holder);

        // Allowed: update on the granted machine.
        $job = $service->queue($this->granted, $package, JobAction::Update);
        $this->assertNotNull($job->id);

        // Denied: install anywhere (capability), update elsewhere (scope).
        try {
            $service->queue($this->granted, $package, JobAction::Install);
            $this->fail('install should be denied by the overlay');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('does not allow', $e->getMessage());
        }

        try {
            $service->queue($this->other, $package, JobAction::Update);
            $this->fail('a machine outside the role scope should be denied');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('does not allow', $e->getMessage());
        }
    }

    public function test_visibility_is_narrowed_to_granted_machines(): void
    {
        $holder = $this->holderOf($this->updaterOfOneMachine());

        $visible = Computer::visibleTo($holder)->pluck('computers.id')->all();

        $this->assertSame([$this->granted->id], $visible);
    }

    public function test_users_without_an_overlay_are_untouched(): void
    {
        $tech = User::factory()->create();
        $tech->forceFill(['client_id' => $this->client->id])->save();
        $tech->assignRole(RoleEnum::Technician->value);

        $this->assertNull($tech->grantedComputerIds());
        $this->assertTrue($tech->mayDeploy('install', $this->granted));
        $this->assertCount(2, Computer::visibleTo($tech)->get());
    }

    public function test_a_site_scoped_role_covers_machines_that_enrol_later(): void
    {
        $siteB = Project::factory()->create(['client_id' => $this->client->id]);
        $machineInB = Computer::factory()->create(['project_id' => $siteB->id]);

        $role = ClientRole::factory()->create([
            'client_id' => $this->client->id,
            'name'      => 'Site A updater',
            'scope'     => 'sites',
        ]);
        $role->projects()->attach($this->project);

        $holder = $this->holderOf($role);
        $package = Package::factory()->create();
        $service = app(DeploymentService::class);
        $this->actingAs($holder);

        // Covered: any machine in site A — including one enrolled AFTER the
        // role was made. That is the whole point of the site scope.
        $late = Computer::factory()->create(['project_id' => $this->project->id]);
        $this->assertNotNull($service->queue($late, $package, JobAction::Update)->id);

        // Denied: a machine in site B.
        try {
            $service->queue($machineInB, $package, JobAction::Update);
            $this->fail('site B should be outside the role');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        // Visibility follows: only site A machines exist for the holder.
        $visible = Computer::visibleTo($holder)->pluck('computers.id')->all();
        sort($visible);
        $this->assertSame([$this->granted->id, $this->other->id, $late->id], $visible);
    }

    public function test_an_existing_member_can_be_moved_between_roles(): void
    {
        $role = $this->updaterOfOneMachine();
        $member = User::factory()->create();
        $member->forceFill(['client_id' => $this->client->id])->save();
        $member->assignRole(RoleEnum::Viewer->value);

        // Ladder → custom.
        Livewire::actingAs($this->owner)
            ->test(TeamIndex::class)
            ->call('setMemberRole', $member->id, 'custom:'.$role->id);

        $member->refresh();
        $this->assertSame($role->id, $member->client_role_id);
        $this->assertTrue($member->hasRole(RoleEnum::Technician->value));

        // Custom → ladder clears the overlay.
        Livewire::actingAs($this->owner)
            ->test(TeamIndex::class)
            ->call('setMemberRole', $member->id, RoleEnum::Manager->value);

        $member->refresh();
        $this->assertNull($member->client_role_id);
        $this->assertTrue($member->hasRole(RoleEnum::Manager->value));
    }

    public function test_an_owners_role_cannot_be_changed_from_the_dropdown(): void
    {
        $role = $this->updaterOfOneMachine();

        Livewire::actingAs($this->owner)
            ->test(TeamIndex::class)
            ->call('setMemberRole', $this->owner->id, 'custom:'.$role->id);

        $this->owner->refresh();
        $this->assertNull($this->owner->client_role_id);
        $this->assertTrue($this->owner->isClientOwner());
    }

    public function test_package_scope_limits_which_software_a_role_may_deploy(): void
    {
        $allowed = Package::factory()->create(['name' => 'Allowed App']);
        $forbidden = Package::factory()->create(['name' => 'Forbidden App']);

        $role = ClientRole::factory()->create([
            'client_id'     => $this->client->id,
            'name'          => 'App-limited updater',
            'can_install'   => true,
            'scope'         => 'all',
            'package_scope' => 'packages',
        ]);
        $role->packages()->attach($allowed);

        $holder = $this->holderOf($role);
        $service = app(DeploymentService::class);
        $this->actingAs($holder);

        $this->assertNotNull($service->queue($this->granted, $allowed, JobAction::Install)->id);

        try {
            $service->queue($this->granted, $forbidden, JobAction::Install);
            $this->fail('a package outside the role list must be denied');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Forbidden App', $e->getMessage());
        }

        // The deploy picker narrows to the allowed software.
        $visible = Package::deployableBy($holder)->pluck('packages.id')->all();
        $this->assertContains($allowed->id, $visible);
        $this->assertNotContains($forbidden->id, $visible);
    }

    public function test_category_scope_covers_every_package_in_the_category(): void
    {
        $category = \App\Models\PackageCategory::factory()->create(['name' => 'Browsers']);
        $inCategory = Package::factory()->create(['package_category_id' => $category->id]);
        $outside = Package::factory()->create();

        $role = ClientRole::factory()->create([
            'client_id'     => $this->client->id,
            'name'          => 'Browser updater',
            'can_update'    => true,
            'scope'         => 'all',
            'package_scope' => 'categories',
        ]);
        $role->packageCategories()->attach($category);

        $holder = $this->holderOf($role);
        $service = app(DeploymentService::class);
        $this->actingAs($holder);

        $this->assertNotNull($service->queue($this->granted, $inCategory, JobAction::Update)->id);

        try {
            $service->queue($this->granted, $outside, JobAction::Update);
            $this->fail('a package outside the category must be denied');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        $role = $this->updaterOfOneMachine();
        $this->holderOf($role);

        Livewire::actingAs($this->owner)
            ->test(ClientRoles::class)
            ->call('delete', $role->id);

        $this->assertNotNull($role->fresh(), 'assigned roles survive deletion attempts');
    }
}
