<?php

namespace Database\Factories;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeploymentJobFactory extends Factory
{
    protected $model = DeploymentJob::class;

    public function definition(): array
    {
        return [
            'computer_id'  => Computer::factory(),
            'package_id'   => Package::factory(),
            'action'       => JobAction::Install,
            'status'       => JobStatus::Pending,
            'priority'     => 5,
            'attempts'     => 0,
            'max_attempts' => 3,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => JobStatus::Running, 'attempts' => 1, 'claimed_at' => now()]);
    }

    public function succeeded(): static
    {
        return $this->state(fn () => ['status' => JobStatus::Succeeded, 'attempts' => 1, 'exit_code' => 0, 'finished_at' => now()]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => JobStatus::Failed, 'attempts' => 3, 'exit_code' => 1, 'finished_at' => now()]);
    }
}
