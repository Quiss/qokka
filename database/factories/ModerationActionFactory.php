<?php

namespace Database\Factories;

use App\Models\ContentPlan;
use App\Models\ModerationAction;
use App\Models\User;
use App\ModerationActionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModerationAction>
 */
class ModerationActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject_type' => ContentPlan::class,
            'subject_id' => ContentPlan::factory(),
            'action' => ModerationActionType::EditPost,
            'metadata' => [],
        ];
    }
}
