<?php

namespace Database\Factories;

use App\Models\Affiliate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Affiliate>
 */
class AffiliateFactory extends Factory
{
    protected $model = Affiliate::class;

    public function definition(): array
    {
        return [
            'name'            => $this->faker->name(),
            'email'           => $this->faker->unique()->safeEmail(),
            'code'            => Str::lower($this->faker->unique()->firstName()),
            'commission_type' => 'percentage',
            'commission_rate' => 20,
            'recurring'       => true,
            'status'          => 'approved',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function fixed(int $cents = 5000): static
    {
        return $this->state(fn () => ['commission_type' => 'fixed', 'commission_rate' => $cents]);
    }
}
