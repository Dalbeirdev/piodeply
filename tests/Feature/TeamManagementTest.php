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
        $this->owner->assignRole(RoleEnum::Manager->value);
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
        $peer = User::factory()->create();
        $peer->forceFill(['client_id' => $this->client->id])->save();
        $peer->assignRole(RoleEnum::Manager->value);

        $page = Livewire::actingAs($this->owner)->test(TeamIndex::class);

        $page->call('remove', $this->owner->id);
        $this->assertNotNull($this->owner->fresh(), 'self-removal must be refused');

        $page->call('remove', $peer->id);
        $this->assertNotNull($peer->fresh(), 'removing a fellow owner must be refused');
    }
}
