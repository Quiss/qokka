<?php

namespace Database\Factories;

use App\Models\Source;
use App\Models\SourceMessage;
use App\Models\SourcePost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceMessage>
 */
class SourceMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_post_id' => SourcePost::factory(),
            'source_id' => Source::factory(),
            'external_message_id' => fake()->unique()->numberBetween(1, 2_000_000_000),
            'text' => fake()->paragraph(),
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => fake()->dateTimeBetween('-24 hours'),
        ];
    }
}
