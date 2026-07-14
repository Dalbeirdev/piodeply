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
            'project_id' => Project::factory(),
            'package_id' => Package::factory(),
            'action'     => 'install',
            'priority'   => 5,
            'is_active'  => true,
        ];
    }
}
