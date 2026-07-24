<?php

namespace Database\Factories;

use App\AiOperation;
use App\Models\AiRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRun>
 */
class AiRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation' => AiOperation::RankAndCluster,
            'model' => 'test/model',
            'prompt_version' => 'v1',
            'request_payload' => [],
            'response_payload' => [],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            'cost_usd' => 0,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }
}
