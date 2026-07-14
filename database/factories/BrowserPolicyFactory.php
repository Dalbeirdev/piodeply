<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\BrowserPolicy>
 */
class BrowserPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'       => 'Block private browsing',
            'project_id' => Project::factory(),
            'type'       => 'disable_incognito',
            'browsers'   => ['all'],
            'action'     => 'disable',
            'status'     => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
