<?php

namespace Database\Factories;

use App\Models\EnterpriseQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnterpriseQuote>
 */
class EnterpriseQuoteFactory extends Factory
{
    protected $model = EnterpriseQuote::class;

    public function definition(): array
    {
        return [
            'company_name'    => $this->faker->company(),
            'contact_name'    => $this->faker->name(),
            'email'           => $this->faker->safeEmail(),
            'phone'           => $this->faker->optional()->phoneNumber(),
            'country'         => $this->faker->optional()->country(),
            'device_count'    => $this->faker->numberBetween(5001, 50000),
            'current_rmm'     => $this->faker->optional()->randomElement(['NinjaOne', 'Atera', 'ConnectWise']),
            'expected_growth' => $this->faker->optional()->randomElement(['Stable', '2x in a year']),
            'notes'           => $this->faker->optional()->sentence(),
            'status'          => 'new',
            'ip'              => $this->faker->ipv4(),
        ];
    }
}
