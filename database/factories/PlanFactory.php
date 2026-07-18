<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $devices = $this->faker->randomElement([20, 50, 100, 250, 500]);
        $monthlyCents = $devices * 100;

        return [
            'slug'                => Str::slug("{$devices}-machines-" . $this->faker->unique()->numberBetween(1, 99999)),
            'name'                => "{$devices} Machines",
            'device_limit'        => $devices,
            'monthly_price_cents' => $monthlyCents,
            'yearly_price_cents'  => $monthlyCents * 10,
            'currency'            => 'usd',
            'features'            => ['Unlimited users', 'Silent deployment'],
            'is_recommended'      => false,
            'is_active'           => true,
            'sort_order'          => $devices,
        ];
    }

    public function recommended(): static
    {
        return $this->state(fn () => ['is_recommended' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
