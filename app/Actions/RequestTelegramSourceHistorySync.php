<?php

namespace App\Actions;

use App\Models\SourceChannel;
use App\Models\TelegramOwnerCommand;
use App\Services\TelegramOwnerCommandDispatcher;
use App\TelegramOwnerCommandType;

class RequestTelegramSourceHistorySync
{
    public function __construct(
        private readonly TelegramOwnerCommandDispatcher $commandDispatcher,
    ) {}

    public function handle(SourceChannel $sourceChannel, int $lookbackHours = 24): ?TelegramOwnerCommand
    {
        $sourceChannel->loadMissing('collectorTelegramAccount');
        $account = $sourceChannel->collectorTelegramAccount;

        if ($account === null || ! $account->is_active) {
            return null;
        }

        return $this->commandDispatcher->dispatch(
            $account,
            TelegramOwnerCommandType::SyncSourceHistory,
            [
                'source_channel_id' => $sourceChannel->id,
                'lookback_hours' => max(1, min(168, $lookbackHours)),
            ],
            "source:{$sourceChannel->id}:history:".max(1, min(168, $lookbackHours)),
            priority: 10,
        );
    }
}
