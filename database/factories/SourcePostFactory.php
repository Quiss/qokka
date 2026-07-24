<?php

namespace Database\Factories;

use App\Models\SourceChannel;
use App\Models\SourcePost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SourcePost>
 */
class SourcePostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_channel_id' => SourceChannel::factory(),
            'canonical_key' => (string) Str::uuid(),
            'text' => fake()->paragraphs(2, true),
            'normalized_text' => fake()->sentence(),
            'metrics' => [
                'views' => fake()->numberBetween(100, 100_000),
                'forwards' => fake()->numberBetween(0, 1_000),
                'reactions' => fake()->numberBetween(0, 5_000),
            ],
            'views' => fake()->numberBetween(100, 100_000),
            'forwards' => fake()->numberBetween(0, 1_000),
            'reactions' => fake()->numberBetween(0, 5_000),
            'comments' => fake()->numberBetween(0, 500),
            'metadata' => [],
            'status' => 'active',
            'posted_at' => fake()->dateTimeBetween('-24 hours'),
        ];
    }
}
