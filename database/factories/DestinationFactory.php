<?php

namespace Database\Factories;

use App\DestinationPlatform;
use App\Models\Destination;
use App\Models\Publication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Destination>
 */
class DestinationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'publication_id' => Publication::factory(),
            'platform' => DestinationPlatform::Telegram,
            'name' => fake()->company().' Telegram',
            'external_id' => '@'.fake()->unique()->userName(),
            'settings' => [],
            'is_active' => true,
        ];
    }
}
