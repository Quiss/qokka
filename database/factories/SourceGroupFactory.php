<?php

namespace Database\Factories;

use App\Models\SourceGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceGroup>
 */
class SourceGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
