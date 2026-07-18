<?php

namespace Tests\Feature;

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke coverage for every public marketing page: each must render (200) and
 * the internal cross-links added across the site must be present. Guards the
 * navigation/SEO linking so a future copy edit can't silently drop them.
 */
class MarketingPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @dataProvider publicRoutes */
    public function test_public_marketing_page_renders(string $path): void
    {
        $this->get($path)->assertOk();
    }

    public static function publicRoutes(): array
    {
        return [
            'home'        => ['/'],
            'about'       => ['/about'],
            'pricing'     => ['/pricing'],
            'contact'     => ['/contact'],
            'get-started' => ['/get-started'],
            'privacy'     => ['/privacy'],
            'brand'       => ['/brand'],
        ];
    }

    public function test_home_cross_links_to_pricing_and_about(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('href="'.route('pricing').'"', false)
            ->assertSee('href="'.route('about').'"', false);
    }

    public function test_pricing_shows_secure_payment_trust_signal(): void
    {
        $this->get('/pricing')->assertOk()
            ->assertSee('Payments are processed securely by')
            ->assertSee('stripe.com/docs/security');
    }

    public function test_get_started_lists_what_happens_next(): void
    {
        $this->get('/get-started')->assertOk()
            ->assertSee('What happens next')
            ->assertSee('We set up your tenant');
    }

    public function test_outbound_references_use_safe_rel_and_new_tab(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('learn.microsoft.com/windows/package-manager/winget', false);
    }
}
