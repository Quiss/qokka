<?php

namespace Database\Factories;

use App\Models\Source;
use App\SourceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => SourceType::Telegram,
            'telegram_peer_id' => fake()->unique()->numberBetween(1_000_000, 2_000_000_000),
            'username' => fake()->unique()->userName(),
            'title' => fake()->company(),
            'weight' => fake()->randomFloat(2, 0.5, 2),
            'is_active' => true,
            'settings' => [],
            'metadata' => [],
        ];
    }

    public function jsonCollection(): static
    {
        return $this->state(fn (): array => [
            'type' => SourceType::JsonCollection,
            'telegram_peer_id' => null,
            'username' => null,
            'endpoint_url' => 'https://feeds.example.com/api/v1/publications',
            'settings' => ['lookback_hours' => 24, 'limit' => 100],
        ]);
    }
}
