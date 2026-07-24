<?php

namespace Database\Factories;

use App\Models\PlannedPost;
use App\Models\PlannedPostRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedPostRevision>
 */
class PlannedPostRevisionFactory extends Factory
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
            'version' => 1,
            'text' => fake()->paragraphs(3, true),
            'risk_flags' => [],
        ];
    }
}
