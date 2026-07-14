<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $plainKey = Project::API_KEY_PREFIX . Str::random(40);

        return [
            'client_id'      => Client::factory(),
            'name'           => ucfirst(fake()->unique()->words(3, true)),
            'description'    => fake()->sentence(),
            'status'         => ProjectStatus::Active,
            'api_key_hash'   => hash('sha256', $plainKey),
            'api_key_prefix' => substr($plainKey, 0, 12),
            'download_token' => Str::lower(Str::random(32)),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => ProjectStatus::Archived]);
    }
}
