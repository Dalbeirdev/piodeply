<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\Client;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Optional 2FA enforcement: the security.require_two_factor setting routes
 * unenrolled users to their profile, without ever blocking enrolment itself.
 */
class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(bool $withTwoFactor = false): User
    {
        $user = User::factory()->create($withTwoFactor ? [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['a', 'b'])),
            'two_factor_confirmed_at' => now(),
        ] : []);
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    public function test_enforcement_is_off_by_default(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertOk();
    }

    public function test_staff_without_two_factor_are_sent_to_their_profile(): void
    {
        app(SettingsService::class)->set('security.require_two_factor', 'staff');

        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertRedirect('/user/profile')
            ->assertSessionHas('two_factor_required');
    }

    public function test_the_profile_stays_reachable_so_enrolment_cannot_dead_lock(): void
    {
        app(SettingsService::class)->set('security.require_two_factor', 'staff');

        $this->actingAs($this->admin())->get('/user/profile')->assertOk();
    }

    public function test_staff_with_confirmed_two_factor_pass_through(): void
    {
        app(SettingsService::class)->set('security.require_two_factor', 'staff');

        $this->actingAs($this->admin(withTwoFactor: true))->get('/dashboard')->assertOk();
    }

    public function test_client_users_are_exempt_in_staff_mode_but_not_in_all_mode(): void
    {
        $client = Client::factory()->create();
        $tenant = User::factory()->create(['client_id' => $client->id]);
        $tenant->assignRole(RoleEnum::Client->value);

        app(SettingsService::class)->set('security.require_two_factor', 'staff');
        $this->actingAs($tenant)->get('/dashboard')->assertOk();

        app(SettingsService::class)->set('security.require_two_factor', 'all');
        $this->actingAs($tenant)->get('/dashboard')->assertRedirect('/user/profile');
    }

    public function test_guests_and_public_pages_are_untouched(): void
    {
        app(SettingsService::class)->set('security.require_two_factor', 'all');

        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
    }

    public function test_admin_users_page_shows_the_two_factor_badge(): void
    {
        $enrolled = User::factory()->create([
            'name' => 'Enrolled Person',
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        User::factory()->create(['name' => 'Unenrolled Person']);

        Livewire::actingAs($this->admin(withTwoFactor: true))
            ->test(\App\Livewire\Admin\ManageUsers::class)
            ->assertSee('Enrolled Person')
            ->assertSee('Enabled')
            ->assertSee('Unenrolled Person')
            ->assertSee('Off');
    }

    public function test_settings_page_persists_the_enforcement_mode(): void
    {
        Livewire::actingAs($this->admin(withTwoFactor: true))
            ->test(\App\Livewire\Admin\SettingsPage::class)
            ->set('require_two_factor', 'staff')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('staff', app(SettingsService::class)->get('security.require_two_factor'));
    }
}
