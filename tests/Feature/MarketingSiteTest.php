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

    public function test_the_about_page_tells_the_story_in_motion(): void
    {
        $response = $this->get('/about')->assertOk();

        $html = $response->getContent();

        // The grid the whole animation runs on: 8x3, each card carrying its
        // paint order and its distance from the portal.
        $this->assertSame(24, substr_count($html, '--i:'), 'expected a full 8x3 grid of machines');
        $this->assertStringContainsString('data-story-caption', $html);

        // The refusal is the point of this section — the three costs we would
        // not pay are named, not implied.
        foreach (['A domain', 'An imaging server', 'A six-figure contract'] as $cost) {
            $response->assertSee($cost);
        }
    }

    /** The motion is decorative; the prose beside it has to carry the story. */
    public function test_the_about_story_survives_without_the_animation(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee('started inside', false)
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
