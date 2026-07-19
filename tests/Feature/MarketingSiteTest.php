<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarketingSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render_without_auth(): void
    {
        foreach (['/', '/about', '/pricing', '/contact', '/privacy', '/get-started'] as $url) {
            $this->get($url)->assertOk()->assertSee('PioDeploy');
        }
    }

    public function test_a_signed_in_visitor_is_sent_to_the_dashboard_not_asked_to_log_in(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['name' => 'Dalbeir Singh']))
            ->get('/about')
            ->assertOk()
            ->assertSee('Go to dashboard')
            ->assertSee('Dalbeir')          // first name only
            ->assertDontSee('>Log in<', false)
            ->assertDontSee('>Get started<', false);
    }

    public function test_a_visitor_who_is_not_signed_in_still_gets_the_pitch(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee('Log in')
            ->assertSee('Get started')
            ->assertDontSee('Go to dashboard');
    }

    /**
     * The branding setting is the company that makes the product. When it is
     * set to the product's own name, "PioDeploy started inside PioDeploy" is
     * what reaches the page — so nothing may name it blindly.
     */
    public function test_the_story_does_not_say_piodeploy_was_built_inside_piodeploy(): void
    {
        app(\App\Services\SettingsService::class)->set('branding.company_name', config('app.name'));

        $this->get('/about')
            ->assertOk()
            ->assertDontSee('inside '.config('app.name'))
            ->assertSee('inside a working MSP');
    }

    public function test_the_story_names_the_house_when_the_setting_is_a_real_company(): void
    {
        app(\App\Services\SettingsService::class)->set('branding.company_name', 'TechPio');

        $this->get('/about')
            ->assertOk()
            ->assertSee('inside TechPio')
            ->assertDontSee('inside a working MSP');
    }

    /** "Company: PioDeploy" on PioDeploy's own contact page says nothing. */
    public function test_contact_does_not_state_the_obvious_company(): void
    {
        app(\App\Services\SettingsService::class)->set('branding.company_name', config('app.name'));

        $this->get('/contact')->assertOk()->assertDontSee('Built by');

        app(\App\Services\SettingsService::class)->set('branding.company_name', 'TechPio');

        $this->get('/contact')->assertOk()->assertSee('Built by')->assertSee('TechPio');
    }

    public function test_contact_sends_a_signed_in_customer_to_their_fleet(): void
    {
        $this->actingAs(\App\Models\User::factory()->create())
            ->get('/contact')
            ->assertOk()
            ->assertSee('Go to your dashboard')
            ->assertDontSee('Already a customer?');
    }

    public function test_the_about_page_shows_the_stat_strip(): void
    {
        // The fragile animated grid was replaced with a plain, always-visible
        // three-number stat strip. Assert the numbers and their labels.
        $this->get('/about')->assertOk()
            ->assertSee('story-stats', false)
            ->assertSee('agent per machine')
            ->assertSee('domains or imaging servers')
            ->assertSee('silent, unattended installs');
    }

    /** The motion is decorative; the prose beside it has to carry the story. */
    public function test_the_about_story_survives_without_the_animation(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee('as an internal tool inside', false)
            ->assertSee('What we believe')
            ->assertSee("Where we're going", false)
            ->assertSee('aria-hidden="true"', false);
    }

    public function test_home_page_replaces_the_laravel_welcome_screen(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Deploy software to your whole fleet')
            ->assertDontSee('Laravel has an incredibly rich ecosystem');
    }

    public function test_nav_links_to_the_real_login(): void
    {
        $this->get('/')->assertOk()->assertSee(url('/login'));
    }

    public function test_contact_form_stores_a_lead_and_redirects(): void
    {
        $this->post('/leads', [
            'type' => 'contact', 'redirect_to' => 'contact',
            'name' => 'Dana Ops', 'email' => 'dana@example.test',
            'company' => 'Acme MSP', 'message' => 'Interested in a demo.',
        ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('lead_ok');

        $this->assertDatabaseHas('leads', [
            'type' => 'contact', 'email' => 'dana@example.test', 'company' => 'Acme MSP',
        ]);
    }

    public function test_access_request_stores_a_lead_and_notifies_subscribers(): void
    {
        Mail::fake();
        NotificationChannel::factory()->events(['lead.received'])->create();

        $this->post('/leads', [
            'type' => 'access_request', 'redirect_to' => 'get-started',
            'name' => 'Sam Lead', 'email' => 'sam@msp.test',
            'company' => 'Sam MSP', 'fleet_size' => '500-2500',
        ])->assertRedirect(route('get-started'));

        $this->assertDatabaseHas('leads', ['type' => 'access_request', 'fleet_size' => '500-2500']);
        Mail::assertSent(\App\Mail\ChannelNotification::class, 1);
    }

    public function test_lead_validation_rejects_bad_input(): void
    {
        $this->post('/leads', [
            'type' => 'contact', 'redirect_to' => 'contact',
            'name' => '', 'email' => 'not-an-email',
        ])->assertSessionHasErrors(['name', 'email']);

        $this->assertSame(0, Lead::count());
    }

    public function test_public_registration_stays_disabled(): void
    {
        // The marketing "Get started" must not reopen self-registration.
        $this->get('/register')->assertNotFound();
    }
}
