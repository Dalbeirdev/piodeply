<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'        => fake()->words(2, true),
            'type'        => 'email',
            'destination' => fake()->safeEmail(),
            'events'      => ['job.failed'],
            'is_active'   => true,
        ];
    }

    public function webhook(string $url = 'https://hooks.example.test/notify'): static
    {
        return $this->state(fn () => ['type' => 'webhook', 'destination' => $url]);
    }

    public function events(array $events): static
    {
        return $this->state(fn () => ['events' => $events]);
    }
}
