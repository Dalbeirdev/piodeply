<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\ManageUsers;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(RoleEnum $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole($role->value));
    }

    // ── Headers ────────────────────────────────────────────────────────

    public function test_security_headers_are_present_on_web_responses(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
    }

    public function test_security_headers_are_present_on_api_responses(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['read']);

        $this->getJson('/api/v1/computers')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    // ── Registration is closed ─────────────────────────────────────────

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruder', 'email' => 'intruder@evil.test',
            'password' => 'SuperSecret123', 'password_confirmation' => 'SuperSecret123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@evil.test']);
    }

    // ── Admin user creation ────────────────────────────────────────────

    public function test_admin_can_create_a_user_with_a_role(): void
    {
        Livewire::actingAs($this->userWithRole(RoleEnum::Admin))
            ->test(ManageUsers::class)
            ->set('newName', 'New Tech')
            ->set('newEmail', 'tech2@techpio.test')
            ->set('newPassword', 'SecurePass123')
            ->set('newRole', RoleEnum::Technician->value)
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::where('email', 'tech2@techpio.test')->firstOrFail();
        $this->assertTrue($user->hasRole(RoleEnum::Technician->value));
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('activity_log', ['description' => 'user_created']);
    }

    public function test_weak_passwords_are_rejected_when_creating_users(): void
    {
        Livewire::actingAs($this->userWithRole(RoleEnum::Admin))
            ->test(ManageUsers::class)
            ->set('newName', 'Weak Pass')
            ->set('newEmail', 'weak@techpio.test')
            ->set('newPassword', 'password') // no numbers, too short
            ->set('newRole', RoleEnum::Viewer->value)
            ->call('createUser')
            ->assertHasErrors('newPassword');
    }

    public function test_super_admin_role_cannot_be_assigned_via_the_form(): void
    {
        Livewire::actingAs($this->userWithRole(RoleEnum::Admin))
            ->test(ManageUsers::class)
            ->set('newName', 'Sneaky')
            ->set('newEmail', 'sneaky@techpio.test')
            ->set('newPassword', 'SecurePass123')
            ->set('newRole', RoleEnum::SuperAdmin->value)
            ->call('createUser')
            ->assertHasErrors('newRole');

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@techpio.test']);
    }

    public function test_manager_cannot_create_users(): void
    {
        // Manager has users.view but not users.create.
        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(ManageUsers::class)
            ->set('newName', 'X')->set('newEmail', 'x@x.test')
            ->set('newPassword', 'SecurePass123')->set('newRole', RoleEnum::Viewer->value)
            ->call('createUser')
            ->assertForbidden();
    }

    // ── Activity log viewer ────────────────────────────────────────────

    public function test_activity_page_shows_audit_entries_with_filters(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);

        activity('rbac')->causedBy($admin)->log('permission_granted');
        activity('policies')->causedBy($admin)->log('exclusion_toggled');

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\ActivityIndex::class)
            ->assertSee('permission granted')
            ->assertSee('exclusion toggled')
            ->set('logFilter', 'rbac')
            ->assertSee('permission granted')
            ->assertDontSee('exclusion toggled');
    }

    public function test_activity_page_respects_permission(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Manager))->get('/activity')->assertOk();
        $this->actingAs($this->userWithRole(RoleEnum::Viewer))->get('/activity')->assertOk();

        $client = User::factory()->create(['client_id' => \App\Models\Client::factory()->create()->id]);
        $client->assignRole(RoleEnum::Client->value);
        $this->actingAs($client)->get('/activity')->assertForbidden();
    }

    // ── security:check command ─────────────────────────────────────────

    public function test_security_check_passes_on_a_healthy_local_setup(): void
    {
        $this->userWithRole(RoleEnum::SuperAdmin);

        $this->artisan('security:check')
            ->expectsOutputToContain('Security check passed')
            ->assertExitCode(0);
    }

    public function test_security_check_warns_without_a_super_admin(): void
    {
        $this->artisan('security:check')
            ->expectsOutputToContain('No Super Admin')
            ->assertExitCode(1);
    }

    public function test_security_check_flags_unbound_client_accounts(): void
    {
        $this->userWithRole(RoleEnum::SuperAdmin);
        $this->userWithRole(RoleEnum::Client); // no client_id

        $this->artisan('security:check')
            ->expectsOutputToContain('no client binding')
            ->assertExitCode(1);
    }

    /* ── the mailer check ──────────────────────────────────────────────
     | It used to test only that MAIL_MAILER was not "log", so a real
     | install shipped with MAIL_HOST=smtp.yourprovider.com, passed
     | cleanly, and every notification failed at the first send.
     */

    public function test_security_check_catches_the_example_mail_host_still_in_place(): void
    {
        $this->userWithRole(RoleEnum::SuperAdmin);
        app()->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.yourprovider.com']);

        $this->artisan('security:check')
            ->expectsOutputToContain('still the example value')
            ->assertExitCode(1);
    }

    public function test_security_check_catches_an_empty_mail_host(): void
    {
        $this->userWithRole(RoleEnum::SuperAdmin);
        app()->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);

        $this->artisan('security:check')
            ->expectsOutputToContain('MAIL_HOST is empty')
            ->assertExitCode(1);
    }

    public function test_security_check_still_catches_the_log_mailer(): void
    {
        $this->userWithRole(RoleEnum::SuperAdmin);
        app()->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']);

        $this->artisan('security:check')
            ->expectsOutputToContain('go to a file, not people')
            ->assertExitCode(1);
    }

    public function test_security_check_accepts_a_real_smtp_host(): void
    {
        $this->userWithRole(RoleEnum::SuperAdmin);
        app()->detectEnvironment(fn () => 'production');
        config([
            'mail.default'            => 'smtp',
            'mail.mailers.smtp.host'  => 'smtp.sendgrid.net',
            'mail.from.address'       => 'hello@piodeploy.com',
            'app.debug'               => false,
            'app.url'                 => 'https://piodeploy.com',
            'session.secure'          => true,
            'session.http_only'       => true,
        ]);

        $this->artisan('security:check')
            ->doesntExpectOutputToContain('MAIL_HOST')
            ->assertExitCode(0);
    }
}
