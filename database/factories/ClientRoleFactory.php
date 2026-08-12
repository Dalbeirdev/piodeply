<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientRoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id'     => Client::factory(),
            'name'          => fake()->unique()->jobTitle(),
            'description'   => null,
            'can_install'   => false,
            'can_update'    => true,
            'can_uninstall' => false,
            'all_computers' => true,
        ];
    }
}
