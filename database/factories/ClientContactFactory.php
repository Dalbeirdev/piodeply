<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientContactFactory extends Factory
{
    protected $model = ClientContact::class;

    public function definition(): array
    {
        return [
            'client_id'  => Client::factory(),
            'name'       => fake()->name(),
            'title'      => fake()->jobTitle(),
            'email'      => fake()->safeEmail(),
            'phone'      => fake()->phoneNumber(),
            'is_primary' => false,
        ];
    }
}
