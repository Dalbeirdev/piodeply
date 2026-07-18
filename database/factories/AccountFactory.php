<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'name'   => $this->faker->company(),
            'status' => 'none',
        ];
    }

    public function onTrial(): static
    {
        return $this->state(fn () => [
            'status'        => 'trialing',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }
}
