<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Team\TeamIndex;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The client owner's Team page: invite technicians into their own tenant
 * and nowhere else.
 */
class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->client = Client::factory()->create();
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['client_id' => $this->client->id])->save();
        $this->owner->assignRole(RoleEnum::ClientOwner->value);
    }

    public function test_an_owner_can_add_a_technician_bound_to_their_own_client(): void
    {
        Livewire::actingAs($this->owner)
            ->test(TeamIndex::class)
            ->set('newName', 'Tech One')
            ->set('newEmail', 'tech1@client.example')
            ->set('newPassword', 'Tech-password-99')
            ->set('newRole', RoleEnum::Technician->value)
            ->call('create');

        $tech = User::where('email', 'tech1@client.example')->sole();
        $this->assertSame($this->client->id, $tech->client_id, 'binding comes from the session, not the form');
        $this->assertTrue($tech->hasRole(RoleEnum::Technician->value));

        $this->actingAs($tech)->get('/dashboard')->assertOk();
    }

    public function test_an_owner_cannot_grant_manager_or_admin(): void
    {
        Livewire::actingAs($this->owner)
            ->test(TeamIndex::class)
            ->set('newName', 'Sneaky')
            ->set('newEmail', 'sneaky@client.example')
            ->set('newPassword', 'Sneaky-pass-123')
            ->set('newRole', RoleEnum::Admin->value)
            ->call('create')
            ->assertHasErrors('newRole');

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@client.example']);
    }

    public function test_the_owner_grants_the_whole_ladder_inside_their_own_organisation(): void
    {
        foreach ([RoleEnum::ClientOwner, RoleEnum::Manager, RoleEnum::Technician, RoleEnum::Viewer] as $i => $role) {
            Livewire::actingAs($this->owner)
                ->test(TeamIndex::class)
                ->set('newName', "Person {$i}")
                ->set('newEmail', "person{$i}@client.example")
                ->set('newPassword', 'Strong-pass-1234')
                ->set('newRole', $role->value)
                ->call('create')
                ->assertHasNoErrors();

            $created = User::where('email', "person{$i}@client.example")->first();
            $this->assertTrue($created->hasRole($role->value));
            // Every level is bound to the granting owner's client — authority
            // never reaches past their own environment.
            $this->assertSame($this->client->id, $created->client_id);
        }
    }

    public function test_a_manager_runs_the_fleet_but_not_the_account(): void
    {
        $manager = User::factory()->create(['client_id' => $this->client->id]);
        $manager->assignRole(RoleEnum::Manager->value);

        // The fleet: yes.
        $this->actingAs($manager)->get('/computers')->assertOk();
        $this->actingAs($manager)->get('/projects')->assertOk();

        // The account: no. Managing people and paying the bill stay with
        // the owner, so a Manager can be handed real authority safely.
        $this->actingAs($manager)->get('/team')->assertForbidden();

        $nav = collect(app(\App\Services\NavigationService::class)->items($manager))->pluck('label');
        $this->assertFalse($nav->contains('Team'), 'no link to a page they would only be refused');
        $this->assertFalse($nav->contains('Billing'));
    }

    public function test_an_owner_can_remove_a_manager_they_granted(): void
    {
        $manager = User::factory()->create(['client_id' => $this->client->id]);
        $manager->assignRole(RoleEnum::Manager->value);

        Livewire::actingAs($this->owner)->test(TeamIndex::class)->call('remove', $manager->id);

        $this->assertNull($manager->fresh(), 'a Manager is the owner\'s to manage');
    }

    public function test_the_page_is_closed_to_unbound_staff_and_to_technicians(): void
    {
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Manager->value));
        $this->actingAs($staff)->get('/team')->assertForbidden();

        $tech = User::factory()->create();
        $tech->forceFill(['client_id' => $this->client->id])->save();
        $tech->assignRole(RoleEnum::Technician->value);
        $this->actingAs($tech)->get('/team')->assertForbidden();
    }

    public function test_members_of_other_clients_are_invisible_and_untouchable(): void
    {
        $otherClient = Client::factory()->create();
        $outsider = User::factory()->create();
        $outsider->forceFill(['client_id' => $otherClient->id])->save();
        $outsider->assignRole(RoleEnum::Technician->value);

        $page = Livewire::actingAs($this->owner)->test(TeamIndex::class);
        $page->assertDontSee($outsider->email);

        try {
            $page->call('remove', $outsider->id);
            $this->fail('removing another tenant\'s user must fail');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }

        $this->assertNotNull($outsider->fresh());
    }

    public function test_an_owner_cannot_remove_themselves_or_another_owner(): void
    {
        // A fellow OWNER is untouchable; a Manager they granted is not.
        $peer = User::factory()->create();
        $peer->forceFill(['client_id' => $this->client->id])->save();
        $peer->assignRole(RoleEnum::ClientOwner->value);

        $page = Livewire::actingAs($this->owner)->test(TeamIndex::class);

        $page->call('remove', $this->owner->id);
        $this->assertNotNull($this->owner->fresh(), 'self-removal must be refused');

        $page->call('remove', $peer->id);
        $this->assertNotNull($peer->fresh(), 'removing a fellow owner must be refused');
    }
}
