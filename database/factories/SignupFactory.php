<?php

namespace Database\Factories;

use App\Models\Signup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class SignupFactory extends Factory
{
    protected $model = Signup::class;

    public function definition(): array
    {
        return [
            'company_name'  => fake()->company(),
            'contact_name'  => fake()->name(),
            'email'         => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('secret-password-1'),
            'phone'         => fake()->phoneNumber(),
            'country'       => fake()->country(),
            'machines'      => fake()->numberBetween(5, 500),
            'monthly_cents' => fake()->numberBetween(500, 50000),
            'currency'      => 'usd',
            'status'        => Signup::STATUS_PENDING_PAYMENT,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status'  => Signup::STATUS_PAID,
            'paid_at' => now(),
            'payment_reference' => 'cs_test_'.fake()->uuid(),
        ]);
    }
}
