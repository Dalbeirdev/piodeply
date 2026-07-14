<?php

namespace Database\Factories;

use App\Models\Computer;
use App\Models\ComputerSoftware;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComputerSoftwareFactory extends Factory
{
    protected $model = ComputerSoftware::class;

    public function definition(): array
    {
        return [
            'computer_id' => Computer::factory(),
            'name'        => ucfirst(fake()->unique()->words(2, true)),
            'version'     => fake()->semver(),
            'publisher'   => fake()->company(),
            'source'      => 'registry',
        ];
    }
}
