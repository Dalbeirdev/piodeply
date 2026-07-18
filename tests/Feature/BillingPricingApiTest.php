<?php

namespace Tests\Feature;

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingPricingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_plans_endpoint_is_public_and_lists_active_plans(): void
    {
        $this->getJson('/api/v1/billing/plans')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.device_limit', 20)
            ->assertJsonPath('data.0.monthly', 16)
            ->assertJsonStructure(['data' => [['id', 'slug', 'name', 'device_limit', 'monthly', 'yearly', 'per_device', 'features', 'is_recommended']]]);
    }

    public function test_exactly_one_plan_is_recommended(): void
    {
        $data = $this->getJson('/api/v1/billing/plans')->json('data');
        $recommended = array_filter($data, fn ($p) => $p['is_recommended'] === true);
        $this->assertCount(1, $recommended);
        $this->assertSame(100, array_values($recommended)[0]['device_limit']);
    }

    public function test_calculate_returns_a_quote_for_a_device_count(): void
    {
        $this->postJson('/api/v1/billing/pricing/calculate', ['devices' => 75])
            ->assertOk()
            ->assertJsonPath('data.is_enterprise', false)
            ->assertJsonPath('data.plan_name', '100 Machines')
            ->assertJsonPath('data.monthly', 48)
            ->assertJsonPath('data.plan.slug', '100-machines');
    }

    public function test_calculate_flags_enterprise_above_the_largest_plan(): void
    {
        $this->postJson('/api/v1/billing/pricing/calculate', ['devices' => 8000])
            ->assertOk()
            ->assertJsonPath('data.is_enterprise', true)
            ->assertJsonPath('data.plan', null);
    }

    public function test_calculate_validates_the_device_count(): void
    {
        $this->postJson('/api/v1/billing/pricing/calculate', [])
            ->assertUnprocessable()->assertJsonValidationErrors('devices');

        $this->postJson('/api/v1/billing/pricing/calculate', ['devices' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('devices');

        $this->postJson('/api/v1/billing/pricing/calculate', ['devices' => 'lots'])
            ->assertUnprocessable()->assertJsonValidationErrors('devices');
    }
}
