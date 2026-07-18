<?php

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code'           => strtoupper($this->faker->unique()->bothify('SAVE##??')),
            'name'           => $this->faker->words(2, true),
            'type'           => 'percent',
            'value'          => 20,
            'currency'       => 'usd',
            'duration'       => 'once',
            'auto_apply'     => false,
            'is_active'      => true,
            'times_redeemed' => 0,
        ];
    }

    public function percent(int $value = 20): static
    {
        return $this->state(fn () => ['type' => 'percent', 'value' => $value]);
    }

    public function fixed(int $cents = 1000): static
    {
        return $this->state(fn () => ['type' => 'fixed', 'value' => $cents]);
    }

    public function trialDays(int $days = 30): static
    {
        return $this->state(fn () => ['type' => 'trial_days', 'value' => $days]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['redeem_by' => now()->subDay()]);
    }
}
