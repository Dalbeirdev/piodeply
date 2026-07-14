<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class PackageVersionFactory extends Factory
{
    protected $model = PackageVersion::class;

    public function definition(): array
    {
        return [
            'package_id'    => Package::factory()->msi(),
            'version'       => fake()->unique()->semver(),
            'installer_url' => 'https://downloads.example.test/' . fake()->uuid() . '.msi',
            'sha256'        => hash('sha256', fake()->uuid()),
            'silent_args'   => '/qn /norestart',
            'release_date'  => fake()->dateTimeBetween('-1 year'),
            'is_latest'     => false,
        ];
    }

    public function latest(): static
    {
        return $this->state(fn () => ['is_latest' => true]);
    }
}
