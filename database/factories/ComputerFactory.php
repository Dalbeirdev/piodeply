<?php

namespace Database\Factories;

use App\Models\Computer;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ComputerFactory extends Factory
{
    protected $model = Computer::class;

    public function definition(): array
    {
        $manufacturers = ['Dell Inc.', 'LENOVO', 'HP', 'Microsoft Corporation', 'ASUS'];

        return [
            'project_id'       => Project::factory(),
            'agent_uuid'       => (string) Str::uuid(),
            'agent_version'    => '1.0.' . fake()->numberBetween(0, 20),
            'last_seen_at'     => now()->subMinutes(fake()->numberBetween(0, 120)),
            'hostname'         => strtoupper(fake()->unique()->bothify('PC-####-??')),
            'serial_number'    => strtoupper(fake()->bothify('??######')),
            'manufacturer'     => fake()->randomElement($manufacturers),
            'model'            => fake()->bothify('Model ###?'),
            'os_name'          => 'Microsoft Windows 11 Pro',
            'os_version'       => '10.0.26100',
            'windows_build'    => '26100.' . fake()->numberBetween(1000, 4000),
            'cpu'              => fake()->randomElement(['Intel Core i5-13500', 'Intel Core i7-1355U', 'AMD Ryzen 7 7840U']),
            'ram_bytes'        => fake()->randomElement([8, 16, 32]) * 1024 ** 3,
            'disk_total_bytes' => 512 * 1024 ** 3,
            'disk_free_bytes'  => fake()->numberBetween(50, 400) * 1024 ** 3,
            'public_ip'        => fake()->ipv4(),
            'private_ip'       => '192.168.1.' . fake()->numberBetween(2, 254),
            'mac_address'      => strtoupper(fake()->macAddress()),
            'secure_boot'      => true,
            'tpm_enabled'      => true,
            'tpm_version'      => '2.0',
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subSeconds(30)]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subHours(3)]);
    }

    public function neverSeen(): static
    {
        return $this->state(fn () => ['last_seen_at' => null, 'agent_version' => null]);
    }
}
