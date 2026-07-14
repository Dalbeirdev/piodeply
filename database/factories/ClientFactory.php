<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'company_name'  => fake()->unique()->company(),
            'email'         => fake()->unique()->companyEmail(),
            'phone'         => fake()->phoneNumber(),
            'address_line1' => fake()->streetAddress(),
            'city'          => fake()->city(),
            'state'         => fake()->word(),
            'postal_code'   => fake()->postcode(),
            'country'       => fake()->country(),
            'timezone'      => fake()->randomElement(['UTC', 'America/New_York', 'Europe/London', 'Asia/Kolkata']),
            'status'        => ClientStatus::Active,
            'billing_email' => fake()->companyEmail(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => ClientStatus::Suspended]);
    }
}
