<?php

namespace Database\Factories;

use App\CandidateStatus;
use App\Models\ContentPlan;
use App\Models\StoryCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryCandidate>
 */
class StoryCandidateFactory extends Factory
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
            'title' => fake()->sentence(6),
            'summary' => fake()->paragraph(),
            'score' => fake()->randomFloat(3, 0, 100),
            'score_breakdown' => [],
            'ai_reason' => fake()->sentence(),
            'risk_flags' => [],
            'status' => CandidateStatus::Pending,
        ];
    }
}
