<?php

namespace Database\Factories;

use App\Models\SourceChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceChannel>
 */
class SourceChannelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_peer_id' => fake()->unique()->numberBetween(1_000_000, 2_000_000_000),
            'username' => fake()->unique()->userName(),
            'title' => fake()->company(),
            'weight' => fake()->randomFloat(2, 0.5, 2),
            'is_active' => true,
            'metadata' => [],
        ];
    }
}
