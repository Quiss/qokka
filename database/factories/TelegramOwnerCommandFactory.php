<?php

namespace Database\Factories;

use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\TelegramOwnerCommandStatus;
use App\TelegramOwnerCommandType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TelegramOwnerCommand> */
class TelegramOwnerCommandFactory extends Factory
{
    protected $model = TelegramOwnerCommand::class;

    public function definition(): array
    {
        return [
            'telegram_account_id' => TelegramAccount::factory(),
            'type' => TelegramOwnerCommandType::DownloadMedia,
            'status' => TelegramOwnerCommandStatus::Pending,
            'deduplication_key' => fake()->uuid(),
            'payload' => ['media_asset_id' => fake()->numberBetween(1, 10_000)],
            'priority' => 100,
            'attempts' => 0,
            'max_attempts' => 3,
            'available_at' => now(),
        ];
    }
}
