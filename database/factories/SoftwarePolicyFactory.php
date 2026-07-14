<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\SoftwarePolicy>
 */
class SoftwarePolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id'   => Project::factory(),
            'package_id'   => Package::factory(),
            'action'       => 'install',
            'mode'         => 'enforce',
            'version_mode' => 'latest',
            'priority'     => 5,
        ];
    }

    public function audit(): static
    {
        return $this->state(fn () => ['mode' => 'audit']);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['mode' => 'disabled']);
    }
}
