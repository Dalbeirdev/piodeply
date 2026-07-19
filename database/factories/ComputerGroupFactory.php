<?php

namespace Database\Factories;

use App\Models\ComputerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComputerGroup> */
class ComputerGroupFactory extends Factory
{
    protected $model = ComputerGroup::class;

    public function definition(): array
    {
        return [
            'name'        => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'created_by'  => null,
        ];
    }
}
