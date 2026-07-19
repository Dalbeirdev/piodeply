<?php

namespace Database\Factories;

use App\Models\BrowserPolicyTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrowserPolicyTemplate> */
class BrowserPolicyTemplateFactory extends Factory
{
    protected $model = BrowserPolicyTemplate::class;

    public function definition(): array
    {
        return [
            'name'        => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'policies'    => [
                ['type' => 'disable_incognito', 'action' => 'disable'],
                ['type' => 'disable_password_saving', 'action' => 'disable'],
            ],
            'created_by'  => null,
        ];
    }
}
