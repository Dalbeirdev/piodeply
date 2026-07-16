<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A permission answers "may this user rotate keys?" — never "whose keys?".
 * Only view() asked the second question, so a manager scoped to one client
 * could rotate another client's API key and brick that client's whole fleet.
 */
class ProjectPolicyTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Project $theirs;

    private User $boundToSomeoneElse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $mine = Client::factory()->create();
        $this->theirs = Project::factory()->create(['client_id' => Client::factory()->create()->id]);

        $this->boundToSomeoneElse = tap(
            User::factory()->create(['client_id' => $mine->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Manager->value)
        );
    }

    public function test_a_client_bound_manager_cannot_rotate_another_clients_api_key(): void
    {
        $this->assertFalse($this->boundToSomeoneElse->can('rotateApiKey', $this->theirs));
    }

    public function test_a_client_bound_manager_cannot_touch_another_clients_project(): void
    {
        foreach (['view', 'update', 'delete', 'restore'] as $ability) {
            $this->assertFalse(
                $this->boundToSomeoneElse->can($ability, $this->theirs),
                "{$ability} must be denied across tenants"
            );
        }
    }

    public function test_a_client_bound_manager_still_manages_their_own_projects(): void
    {
        $mine = Project::factory()->create(['client_id' => $this->boundToSomeoneElse->client_id]);

        foreach (['view', 'update', 'rotateApiKey', 'delete'] as $ability) {
            $this->assertTrue(
                $this->boundToSomeoneElse->can($ability, $mine),
                "{$ability} must be allowed within the user's own tenant"
            );
        }
    }

    public function test_unbound_staff_are_unrestricted(): void
    {
        $admin = tap(User::factory()->create(['client_id' => null]), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        $this->assertTrue($admin->can('rotateApiKey', $this->theirs));
        $this->assertTrue($admin->can('update', $this->theirs));
    }

    /** Unbound means locked out, not waved through. */
    public function test_a_client_account_with_no_client_set_can_reach_nothing(): void
    {
        $unbound = tap(User::factory()->create(['client_id' => null]), fn (User $u) => $u->assignRole(RoleEnum::Client->value));

        $this->assertFalse($unbound->can('view', $this->theirs));
        $this->assertFalse($unbound->can('rotateApiKey', $this->theirs));
    }
}
