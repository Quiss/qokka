<?php

namespace Database\Factories;

use App\Models\TelegramAccount;
use App\TelegramAccountStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramAccount>
 */
class TelegramAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'name' => fake()->unique()->userName(),
            'telegram_user_id' => fake()->unique()->numberBetween(100000, 999999999),
            'username' => fake()->unique()->userName(),
            'status' => TelegramAccountStatus::Authorized,
            'is_active' => true,
            'authorized_at' => now(),
        ];
    }
}
