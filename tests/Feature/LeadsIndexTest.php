<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\LeadsIndex;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The website always stored its enquiries; nothing ever showed them. A lead
 * was visible only as a notification, so an unconfigured mailer turned a real
 * prospect into silence.
 */
class LeadsIndexTest extends TestCase
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

    private function lead(array $attributes = []): Lead
    {
        return Lead::create([
            'type'    => 'access_request',
            'name'    => 'Jane Rivera',
            'email'   => 'jane@acme.test',
            'company' => 'Acme IT',
            'message' => 'We run 400 endpoints across nine sites.',
            ...$attributes,
        ]);
    }

    /** The point of the page: a submission is visible with no email at all. */
    public function test_an_enquiry_is_visible_without_any_notification_being_sent(): void
    {
        $this->lead();

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->assertSee('Jane Rivera')
            ->assertSee('jane@acme.test')
            ->assertSee('Acme IT')
            ->assertSee('400 endpoints');
    }

    public function test_the_website_form_lands_here(): void
    {
        $this->post('/leads', [
            'type' => 'access_request', 'name' => 'Sam Okafor', 'email' => 'sam@fleet.test',
            'company' => 'Fleet Co', 'fleet_size' => '250', 'redirect_to' => 'get-started',
        ])->assertRedirect();

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->assertSee('Sam Okafor')
            ->assertSee('250');
    }

    public function test_open_work_shows_first_and_handled_leads_step_aside(): void
    {
        $this->lead(['name' => 'Still Waiting']);
        $this->lead(['name' => 'Dealt With', 'handled_at' => now()]);

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->assertSee('Still Waiting')
            ->assertDontSee('Dealt With')
            ->set('openOnly', false)
            ->assertSee('Dealt With');
    }

    public function test_marking_handled_and_reopening(): void
    {
        $lead = $this->lead();

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->call('markHandled', $lead->id);

        $this->assertNotNull($lead->fresh()->handled_at);

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->set('openOnly', false)
            ->call('markHandled', $lead->id);

        $this->assertNull($lead->fresh()->handled_at);
    }

    public function test_enquiries_can_be_filtered_by_type(): void
    {
        $this->lead(['type' => 'access_request', 'name' => 'Wants Access']);
        $this->lead(['type' => 'contact', 'name' => 'Just Asking']);

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->set('type', 'contact')
            ->assertSee('Just Asking')
            ->assertDontSee('Wants Access');
    }

    public function test_search_finds_by_company(): void
    {
        $this->lead(['company' => 'Northwind Ltd', 'name' => 'Findable']);
        $this->lead(['company' => 'Other Co', 'name' => 'Hidden']);

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->set('search', 'Northwind')
            ->assertSee('Findable')
            ->assertDontSee('Hidden');
    }

    /* ─────────── it is customer data ─────────── */

    public function test_a_viewer_cannot_read_the_enquiries(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        $this->actingAs($viewer)->get(route('admin.leads'))->assertForbidden();
    }

    /**
     * markHandled() carries its own check as well as the page's. Livewire runs
     * an action before it renders, so the render guard alone would not stop a
     * crafted request — belt and braces, and cheap.
     */
    public function test_marking_handled_checks_permission_in_its_own_right(): void
    {
        $lead = $this->lead();
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        $this->actingAs($viewer);

        try {
            app(LeadsIndex::class)->markHandled($lead->id);
            $this->fail('a viewer must not be able to mark an enquiry handled');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull($lead->fresh()->handled_at);
    }

    public function test_it_appears_in_the_admin_section_of_the_nav(): void
    {
        $labels = collect(app(\App\Services\NavigationService::class)->groups($this->admin()))
            ->firstWhere('label', \App\Services\NavigationService::ADMIN)['items'];

        $this->assertContains('Enquiries', array_column($labels, 'label'));
    }
}
