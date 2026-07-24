<?php

namespace Database\Factories;

use App\Models\ContentPlan;
use App\Models\PlannedPost;
use App\Models\StoryCandidate;
use App\PlannedPostStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedPost>
 */
class PlannedPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_plan_id' => ContentPlan::factory(),
            'story_candidate_id' => StoryCandidate::factory(),
            'text' => fake()->paragraphs(3, true),
            'scheduled_at' => now()->addDay(),
            'status' => PlannedPostStatus::FinalReview,
            'risk_flags' => [],
        ];
    }
}
