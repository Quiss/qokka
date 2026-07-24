<?php

namespace Database\Factories;

use App\ContentPlanStatus;
use App\Models\ContentPlan;
use App\Models\Publication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPlan>
 */
class ContentPlanFactory extends Factory
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
            'plan_date' => today()->addDay(),
            'status' => ContentPlanStatus::CandidateReview,
            'slot_schedule' => [],
            'candidate_target' => 9,
        ];
    }
}
