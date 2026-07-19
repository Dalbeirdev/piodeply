<?php

namespace Tests\Feature;

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Search-engine + social surface: sitemap, robots, canonical/Open Graph/
 * Twitter tags and JSON-LD structured data.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_lists_the_public_pages(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('<urlset', false)
            ->assertSee(route('home'), false)
            ->assertSee(route('features'), false)
            ->assertSee(route('pricing'), false)
            ->assertSee(route('about'), false);
    }

    public function test_robots_blocks_the_app_and_links_the_sitemap(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /dashboard')
            ->assertSee('Sitemap: '.route('sitemap'), false);
    }

    public function test_head_carries_open_graph_twitter_and_canonical(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('rel="canonical"', false)
            ->assertSee('property="og:title"', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('name="twitter:card"', false)
            ->assertSee('og-image.png', false);
    }

    public function test_home_emits_organization_software_and_faq_structured_data(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('"@type":"Organization"', false)
            ->assertSee('"@type":"SoftwareApplication"', false)
            ->assertSee('"@type":"FAQPage"', false);
    }

    public function test_pricing_emits_faq_structured_data(): void
    {
        $this->seed(PlanSeeder::class);

        $this->get('/pricing')
            ->assertOk()
            ->assertSee('"@type":"FAQPage"', false);
    }

    public function test_the_social_share_image_ships(): void
    {
        $this->assertFileExists(public_path('img/og-image.png'));
    }
}
