<?php

namespace App\Actions;

use App\Models\Source;
use App\Models\TelegramOwnerCommand;
use App\Services\TelegramOwnerCommandDispatcher;
use App\TelegramOwnerCommandType;

class RequestTelegramSourceHistorySync
{
    public function __construct(
        private readonly TelegramOwnerCommandDispatcher $commandDispatcher,
    ) {}

    public function handle(Source $source, int $lookbackHours = 24): ?TelegramOwnerCommand
    {
        if (! $source->isTelegram()) {
            return null;
        }

        $source->loadMissing('collectorTelegramAccount');
        $account = $source->collectorTelegramAccount;

        if ($account === null || ! $account->is_active) {
            return null;
        }

        return $this->commandDispatcher->dispatch(
            $account,
            TelegramOwnerCommandType::SyncSourceHistory,
            [
                'source_id' => $source->id,
                'lookback_hours' => max(1, min(168, $lookbackHours)),
            ],
            "source:{$source->id}:history:".max(1, min(168, $lookbackHours)),
            priority: 10,
        );
    }
}
