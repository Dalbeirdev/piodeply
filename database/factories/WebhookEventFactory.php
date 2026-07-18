<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    public function definition(): array
    {
        return [
            'stripe_id' => 'evt_' . $this->faker->unique()->bothify('###########'),
            'type'      => $this->faker->randomElement(['invoice.paid', 'invoice.payment_failed', 'customer.subscription.updated']),
            'status'    => 'received',
            'payload'   => ['id' => 'evt_x', 'type' => 'invoice.paid', 'data' => ['object' => []]],
            'attempts'  => 0,
        ];
    }
}
