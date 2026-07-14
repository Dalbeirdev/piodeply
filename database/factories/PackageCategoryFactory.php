<?php

namespace Database\Factories;

use App\Models\PackageCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PackageCategoryFactory extends Factory
{
    protected $model = PackageCategory::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'name'       => $name,
            'slug'       => Str::slug($name),
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }
}
