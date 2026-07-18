<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The seven fixed plans. Yearly price is ten months of the monthly rate
 * (two months free), so upgrading to annual always shows a real saving.
 *
 * Idempotent by slug: re-seeding updates prices/features but never
 * duplicates a plan, and leaves any Stripe price IDs already attached.
 */
class PlanSeeder extends Seeder
{
    /** Two months free on annual billing. */
    private const YEARLY_MONTHS = 10;

    public function run(): void
    {
        // [device_limit, monthly_dollars, recommended]
        $tiers = [
            [20,   16,  false],
            [50,   28,  false],
            [100,  48,  true],   // the sweet-spot plan gets the badge
            [250,  108, false],
            [500,  208, false],
            [1000, 308, false],
            [5000, 1108, false],
        ];

        $baseFeatures = [
            'Unlimited users & admins',
            'Silent app deployment (winget catalogue)',
            'Desired-state software policies',
            'Real-time fleet compliance',
            'Software & hardware inventory',
            'Browser policy management',
        ];

        $sort = 0;
        foreach ($tiers as [$devices, $monthly, $recommended]) {
            $monthlyCents = $monthly * 100;

            $features = $baseFeatures;
            if ($devices >= 250) {
                $features[] = 'Priority email & chat support';
            }
            if ($devices >= 500) {
                $features[] = 'Guided onboarding';
            }
            if ($devices >= 1000) {
                $features[] = 'Dedicated account manager';
            }

            Plan::updateOrCreate(
                ['slug' => Str::slug("{$devices}-machines")],
                [
                    'name'                => "{$devices} Machines",
                    'device_limit'        => $devices,
                    'monthly_price_cents' => $monthlyCents,
                    'yearly_price_cents'  => $monthlyCents * self::YEARLY_MONTHS,
                    'currency'            => 'usd',
                    'features'            => $features,
                    'is_recommended'      => $recommended,
                    'is_active'           => true,
                    'sort_order'          => $sort++,
                ]
            );
        }
    }
}
