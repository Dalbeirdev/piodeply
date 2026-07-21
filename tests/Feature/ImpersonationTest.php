<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Http\Controllers\ImpersonationController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::SuperAdmin->value));
        $this->technician = tap(User::factory()->create(['name' => 'Tech Tina']), fn (User $u) => $u->assignRole(RoleEnum::Technician->value));
    }

    /** Drop the memoised guard so the next request re-reads the session. */
    private function freshAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_super_admin_can_impersonate_and_return(): void
    {
        $this->actingAs($this->superAdmin)
            ->post("/admin/impersonate/{$this->technician->id}")
            ->assertRedirect(route('dashboard'));

        $this->freshAuth();
        $this->assertAuthenticatedAs($this->technician);
        $this->assertSame($this->superAdmin->id, session(ImpersonationController::SESSION_KEY));
        $this->assertDatabaseHas('activity_log', [
            'description' => 'impersonation_started',
            'causer_id'   => $this->superAdmin->id,
            'subject_id'  => $this->technician->id,
        ]);

        // Banner is visible while impersonating
        $this->get('/dashboard')->assertSee('Impersonating')->assertSee('Tech Tina');

        // Return to the original account
        $this->post('/impersonate/leave')->assertRedirect(route('admin.users'));
        $this->freshAuth();
        $this->assertAuthenticatedAs($this->superAdmin);
        $this->assertFalse(session()->has(ImpersonationController::SESSION_KEY));
        $this->assertDatabaseHas('activity_log', ['description' => 'impersonation_ended']);
    }

    public function test_refreshing_the_impersonation_tab_lands_somewhere_sane(): void
    {
        // The start form opens a new tab, so this URL sits in that tab's
        // history — a refresh is a GET and used to return a raw 405.
        $this->actingAs($this->superAdmin)
            ->post("/admin/impersonate/{$this->technician->id}");
        $this->freshAuth();

        $this->get("/admin/impersonate/{$this->technician->id}")
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_get_can_never_start_an_impersonation(): void
    {
        // Starting from a GET would be CSRF-able: a link could switch a
        // Super Admin into someone else's account.
        $this->actingAs($this->superAdmin)
            ->get("/admin/impersonate/{$this->technician->id}")
            ->assertRedirect(route('admin.users'));

        $this->assertFalse(session()->has(ImpersonationController::SESSION_KEY));
        $this->assertAuthenticatedAs($this->superAdmin);
    }

    public function test_non_super_admin_cannot_impersonate(): void
    {
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        $this->actingAs($admin)
            ->post("/admin/impersonate/{$this->technician->id}")
            ->assertForbidden();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_cannot_impersonate_self_or_another_super_admin(): void
    {
        $otherSuper = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::SuperAdmin->value));

        $this->actingAs($this->superAdmin)
            ->post("/admin/impersonate/{$this->superAdmin->id}")
            ->assertForbidden();

        $this->actingAs($this->superAdmin)
            ->post("/admin/impersonate/{$otherSuper->id}")
            ->assertForbidden();
    }

    public function test_cannot_nest_impersonation(): void
    {
        $second = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        $this->actingAs($this->superAdmin)->post("/admin/impersonate/{$this->technician->id}");
        $this->freshAuth();

        $this->post("/admin/impersonate/{$second->id}")->assertForbidden();
        $this->freshAuth();
        $this->assertAuthenticatedAs($this->technician);
    }

    public function test_leave_without_impersonating_is_forbidden(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/impersonate/leave')
            ->assertForbidden();
    }

    public function test_impersonated_client_user_is_tenancy_scoped(): void
    {
        $client = \App\Models\Client::factory()->create(['company_name' => 'Scoped Co']);
        $clientUser = User::factory()->create(['client_id' => $client->id]);
        $clientUser->assignRole(RoleEnum::Client->value);

        $this->actingAs($this->superAdmin)->post("/admin/impersonate/{$clientUser->id}");
        $this->freshAuth();

        // While impersonating, the client portal (not the admin dashboard) renders.
        $this->get('/dashboard')->assertSee('Pending updates')->assertDontSee('Fleet by client');
    }
}
