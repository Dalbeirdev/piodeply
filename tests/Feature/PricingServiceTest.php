<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\PricingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function pricing(): PricingService
    {
        return app(PricingService::class);
    }

    public function test_it_seeds_the_seven_spec_plans_with_exact_prices(): void
    {
        $this->assertSame(7, Plan::count());

        // The published price points, in cents.
        $expected = [20 => 1600, 50 => 2800, 100 => 4800, 250 => 10800, 500 => 20800, 1000 => 30800, 5000 => 110800];
        foreach ($expected as $devices => $cents) {
            $plan = Plan::where('device_limit', $devices)->firstOrFail();
            $this->assertSame($cents, $plan->monthly_price_cents, "monthly for {$devices}");
            $this->assertSame($cents * 10, $plan->yearly_price_cents, "yearly for {$devices} (2 months free)");
        }
    }

    /** @dataProvider deviceTiers */
    public function test_it_recommends_the_smallest_plan_that_fits(int $devices, int $expectedLimit): void
    {
        $plan = $this->pricing()->recommendFor($devices);
        $this->assertNotNull($plan);
        $this->assertSame($expectedLimit, $plan->device_limit);
    }

    public static function deviceTiers(): array
    {
        return [
            'exactly at a ceiling' => [20, 20],
            'one over 20'          => [21, 50],
            'mid band'             => [75, 100],
            'one over 100'         => [101, 250],
            'at 500'               => [500, 500],
            'needs 1000'           => [999, 1000],
            'at the top plan'      => [5000, 5000],
            'a single device'      => [1, 20],
        ];
    }

    public function test_more_than_the_largest_plan_is_enterprise(): void
    {
        $this->assertTrue($this->pricing()->isEnterprise(5001));
        $this->assertNull($this->pricing()->recommendFor(5001));

        $quote = $this->pricing()->calculate(9000);
        $this->assertTrue($quote['is_enterprise']);
        $this->assertNull($quote['plan']);
        $this->assertSame(0, $quote['monthly_cents']);
    }

    public function test_calculate_returns_consistent_derived_figures(): void
    {
        // 100 devices -> 100-machine plan @ $48/mo, $480/yr.
        $quote = $this->pricing()->calculate(100);

        $this->assertFalse($quote['is_enterprise']);
        $this->assertSame('100 Machines', $quote['plan_name']);
        $this->assertSame(4800, $quote['monthly_cents']);
        $this->assertSame(48000, $quote['yearly_cents']);
        $this->assertSame(48.0, $quote['monthly']);
        $this->assertSame(480.0, $quote['yearly']);
        // per device = 4800 / 100 = 48 cents
        $this->assertSame(48, $quote['per_device_cents']);
        // yearly saving vs 12x monthly = 4800*12 - 48000 = 9600 cents.
        // 9600 / 57600 = 16.67% -> rounds to 17% (two months free of twelve).
        $this->assertSame(9600, $quote['savings_cents']);
        $this->assertSame(17, $quote['savings_percent']);
    }

    public function test_a_fleet_smaller_than_the_smallest_plan_still_gets_the_smallest_plan(): void
    {
        $quote = $this->pricing()->calculate(5);
        $this->assertSame(20, $quote['device_limit']);
        $this->assertSame(1600, $quote['monthly_cents']);
    }

    public function test_zero_or_negative_devices_are_floored_to_one(): void
    {
        $this->assertSame(20, $this->pricing()->calculate(0)['device_limit']);
        $this->assertSame(20, $this->pricing()->recommendFor(-10)->device_limit);
    }

    public function test_inactive_plans_are_never_recommended(): void
    {
        Plan::where('device_limit', 20)->update(['is_active' => false]);
        // 5 devices would fit the 20 plan, but it's inactive -> next up is 50.
        $this->assertSame(50, $this->pricing()->recommendFor(5)->device_limit);
    }
}
