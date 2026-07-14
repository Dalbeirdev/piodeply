<?php

namespace Database\Factories;

use App\Enums\Architecture;
use App\Enums\InstallerType;
use App\Models\Package;
use App\Models\PackageCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'package_category_id' => PackageCategory::factory(),
            'name'                => $name,
            'slug'                => Str::slug($name),
            'vendor'              => fake()->company(),
            'homepage'            => 'https://example.test/' . Str::slug($name),
            'description'         => fake()->sentence(),
            'license'             => fake()->randomElement(['Freeware', 'GPL-3.0', 'MIT', 'Commercial']),
            'installer_type'      => InstallerType::Winget,
            'architecture'        => Architecture::X64,
            'winget_id'           => 'Vendor.' . Str::studly($name),
            'is_active'           => true,
        ];
    }

    public function msi(): static
    {
        return $this->state(fn () => ['installer_type' => InstallerType::Msi, 'winget_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
