<?php

namespace Tests\Feature;

use App\Livewire\Admin\BillingSettings;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A dead webhook looks exactly like a quiet week. This panel exists so the
 * difference is visible: it took four weeks to notice that Stripe had stopped
 * reaching this app, while every page still looked fine.
 */
class WebhookHealthPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));
    }

    private function event(string $status, string $type, $at): WebhookEvent
    {
        $e = WebhookEvent::create([
            'stripe_id' => 'evt_'.uniqid(),
            'type'      => $type,
            'status'    => $status,
            'payload'   => ['id' => 'evt'],
        ]);
        $e->forceFill(['created_at' => $at])->save();

        return $e;
    }

    public function test_it_says_so_loudly_when_nothing_has_ever_arrived(): void
    {
        Livewire::actingAs($this->admin())
            ->test(BillingSettings::class)
            ->assertSee('No events ever received')
            ->assertSee('Stripe has never successfully reached this site');
    }

    public function test_a_long_silence_is_flagged(): void
    {
        // Exactly the state this install sat in for four weeks.
        $this->event('processed', 'invoice.paid', now()->subDays(30));

        Livewire::actingAs($this->admin())
            ->test(BillingSettings::class)
            ->assertSee('Nothing received for')
            ->assertSee('stale signing secret');
    }

    public function test_recent_traffic_reads_healthy(): void
    {
        $this->event('processed', 'invoice.paid', now()->subMinutes(5));

        Livewire::actingAs($this->admin())
            ->test(BillingSettings::class)
            ->assertSee('Events arriving normally')
            ->assertSee('invoice.paid');
    }

    public function test_failures_are_surfaced_even_while_events_flow(): void
    {
        $this->event('processed', 'invoice.paid', now()->subHour());
        $this->event('failed', 'customer.subscription.updated', now()->subHour());

        Livewire::actingAs($this->admin())
            ->test(BillingSettings::class)
            ->assertSee('1 failed this week');
    }

    public function test_the_endpoint_check_reports_a_missing_endpoint(): void
    {
        // No Stripe credentials in tests, so the call fails — the panel must
        // report that rather than blow up the page.
        config(['cashier.secret' => null]);

        Livewire::actingAs($this->admin())
            ->test(BillingSettings::class)
            ->call('checkEndpoints')
            ->assertOk()
            ->assertSee('Could not reach Stripe');
    }

    /**
     * The page is gated at mount, so a viewer never reaches the panel or its
     * button. checkEndpoints() authorizes again in its own right, since a
     * Livewire method is addressable regardless of what rendered.
     */
    public function test_a_non_admin_cannot_reach_the_panel_at_all(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));

        $this->actingAs($viewer)->get('/admin/billing')->assertForbidden();
    }
}
