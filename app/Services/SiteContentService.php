<?php

namespace App\Services;

use App\Models\SiteContent;
use Illuminate\Support\Facades\Cache;

/**
 * Editable marketing-site copy, stored in the database and cached. Every
 * key has a default, so an un-edited site renders identically to the
 * shipped design. Admins edit the values on /admin/content.
 */
class SiteContentService
{
    private const CACHE_KEY = 'site-content';

    /**
     * Editable fields, grouped for the admin form. Each: [key, label, type, default].
     *
     * @return array<string, array<int, array{0:string,1:string,2:string,3:string}>>
     */
    public static function schema(): array
    {
        return [
            'Home — hero' => [
                ['home.hero_title', 'Headline', 'text', 'Deploy software to your whole fleet. Silently.'],
                ['home.hero_subtitle', 'Sub-headline', 'textarea', 'Install, update, patch and lock down software across every managed Windows machine — from one portal, with real-time compliance and zero user interruption.'],
            ],
            'Home — call to action' => [
                ['home.cta_title', 'CTA title', 'text', 'Ready to take control of your fleet?'],
                ['home.cta_text', 'CTA text', 'textarea', "Request access and we'll get you set up with a trial tenant and the agent for your first project."],
            ],
            'Pricing' => [
                ['pricing.intro', 'Intro line', 'textarea', 'Priced per endpoint under management. Every plan includes the complete platform — no feature gates, no per-seat admin fees.'],
            ],
            'About' => [
                ['about.intro', 'Intro paragraph', 'textarea', 'We got tired of stitching together scripts, GPOs and half a dozen tools to keep client fleets patched and compliant. So we built the platform we wanted.'],
            ],
            'Contact' => [
                ['contact.email', 'Contact email', 'text', ''],
                ['contact.response_time', 'Response-time note', 'text', 'Usually within one business day'],
            ],
            'Footer' => [
                ['footer.tagline', 'Footer tagline', 'textarea', 'Centralised software deployment, patch management and browser-policy enforcement for Windows fleets.'],
            ],
        ];
    }

    /** @return array<string, string> flat key => default */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::schema() as $fields) {
            foreach ($fields as [$key, , , $default]) {
                $out[$key] = $default;
            }
        }

        return $out;
    }

    public function get(string $key, ?string $default = null): string
    {
        $stored = $this->all()[$key] ?? null;

        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        return $default ?? self::defaults()[$key] ?? '';
    }

    public function set(string $key, ?string $value): void
    {
        SiteContent::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => SiteContent::pluck('value', 'key')->all());
        } catch (\Throwable) {
            return [];
        }
    }
}
