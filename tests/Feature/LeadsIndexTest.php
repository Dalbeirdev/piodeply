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
        $lead = $this->lead();

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->assertSee('Jane Rivera')          // the row
            ->assertSee('Acme IT')
            ->call('view', $lead->id)           // open the detail
            ->assertSee('jane@acme.test')
            ->assertSee('400 endpoints');
    }

    public function test_the_website_form_lands_here(): void
    {
        $this->post('/leads', [
            'type' => 'access_request', 'name' => 'Sam Okafor', 'email' => 'sam@fleet.test',
            'company' => 'Fleet Co', 'fleet_size' => '250', 'redirect_to' => 'get-started',
        ])->assertRedirect();

        $lead = \App\Models\Lead::where('name', 'Sam Okafor')->sole();

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->assertSee('Sam Okafor')
            ->call('view', $lead->id)
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

    /* ─────────── read / delete / reply ─────────── */

    public function test_opening_an_enquiry_marks_it_read(): void
    {
        $lead = $this->lead();
        $this->assertTrue($lead->isUnread());

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->call('view', $lead->id)
            ->assertSet('viewingId', $lead->id);

        $this->assertNotNull($lead->fresh()->read_at);
    }

    public function test_the_header_counts_unread(): void
    {
        $this->lead(['name' => 'One']);
        $this->lead(['name' => 'Two', 'read_at' => now()]);

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->assertViewHas('unreadCount', 1);

        // The header slot renders through the layout, so assert the badge on a
        // real request rather than the isolated component.
        $this->actingAs($this->admin())->get(route('admin.leads'))->assertSee('1 unread');
    }

    public function test_read_can_be_toggled_back_to_unread(): void
    {
        $lead = $this->lead(['read_at' => now()]);

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->call('toggleRead', $lead->id);

        $this->assertNull($lead->fresh()->read_at);
    }

    public function test_handling_an_enquiry_also_marks_it_read(): void
    {
        $lead = $this->lead();

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->call('markHandled', $lead->id);

        $lead->refresh();
        $this->assertNotNull($lead->handled_at);
        $this->assertNotNull($lead->read_at);
    }

    public function test_an_enquiry_can_be_deleted(): void
    {
        $lead = $this->lead();

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)
            ->call('delete', $lead->id);

        $this->assertModelMissing($lead);
    }

    public function test_the_reply_link_is_addressed_and_subject_lined(): void
    {
        $lead = $this->lead(['name' => 'Jane Rivera', 'email' => 'jane@acme.test']);

        $mailto = $lead->replyMailto();

        $this->assertStringStartsWith('mailto:jane@acme.test', $mailto);
        $this->assertStringContainsString('subject=', $mailto);
        $this->assertStringContainsString(rawurlencode('access request'), $mailto);
        $this->assertStringContainsString(rawurlencode('Hi Jane'), $mailto);
    }

    /** Reading is not handling: an unread but pressing enquiry is still work. */
    public function test_reading_does_not_close_the_open_list(): void
    {
        $lead = $this->lead();

        Livewire::actingAs($this->admin())
            ->test(LeadsIndex::class)   // openOnly is true by default
            ->call('view', $lead->id)
            ->assertSee('Jane Rivera'); // still shown; read, not handled
    }

    public function test_a_viewer_cannot_delete_an_enquiry(): void
    {
        $lead = $this->lead();
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        $this->actingAs($viewer);

        try {
            app(LeadsIndex::class)->delete($lead->id);
            $this->fail('a viewer must not delete enquiries');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertModelExists($lead);
    }

    public function test_it_appears_in_the_admin_section_of_the_nav(): void
    {
        $labels = collect(app(\App\Services\NavigationService::class)->groups($this->admin()))
            ->firstWhere('label', \App\Services\NavigationService::ADMIN)['items'];

        $this->assertContains('Enquiries', array_column($labels, 'label'));
    }
}
