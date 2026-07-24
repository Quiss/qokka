<?php

namespace Database\Factories;

use App\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\PlannedPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'planned_post_id' => PlannedPost::factory(),
            'destination_id' => Destination::factory(),
            'status' => DeliveryStatus::Pending,
            'external_message_ids' => [],
            'attempts' => 0,
        ];
    }
}
