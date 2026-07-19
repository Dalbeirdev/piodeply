<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Search-engine surface: a sitemap and a robots policy served from routes so
 * their URLs always resolve against the live APP_URL rather than a hardcoded
 * domain baked into a static file.
 */
class SeoController extends Controller
{
    /** Public marketing pages worth indexing, with a crawl priority. */
    private const PAGES = [
        ['home',        '1.0', 'weekly'],
        ['features',    '0.9', 'weekly'],
        ['pricing',     '0.9', 'weekly'],
        ['about',       '0.7', 'monthly'],
        ['contact',     '0.6', 'monthly'],
        ['get-started', '0.8', 'monthly'],
        ['brand',       '0.3', 'yearly'],
        ['privacy',     '0.3', 'yearly'],
    ];

    public function sitemap(): Response
    {
        $urls = collect(self::PAGES)->map(fn (array $p) => [
            'loc'        => route($p[0]),
            'priority'   => $p[1],
            'changefreq' => $p[2],
        ]);

        $xml = view('seo.sitemap', ['urls' => $urls])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /$',
            // Keep the app, auth and API surfaces out of the index.
            'Disallow: /dashboard',
            'Disallow: /admin',
            'Disallow: /billing',
            'Disallow: /affiliate',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /password',
            'Disallow: /api',
            'Disallow: /download',
            '',
            'Sitemap: '.route('sitemap'),
            '',
        ];

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain']);
    }
}
