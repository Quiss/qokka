<?php

namespace App\Actions;

use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\Services\TelegramOwnerCommandDispatcher;
use App\TelegramAccountStatus;
use App\TelegramOwnerCommandType;

class RequestTelegramSourceVerification
{
    public function __construct(
        private readonly TelegramOwnerCommandDispatcher $commandDispatcher,
    ) {}

    public function handle(SourceChannel $sourceChannel): int
    {
        $count = 0;

        TelegramAccount::query()
            ->where('is_active', true)
            ->whereIn('status', [
                TelegramAccountStatus::Authorized,
                TelegramAccountStatus::Connected,
            ])
            ->orderBy('id')
            ->each(function (TelegramAccount $account) use ($sourceChannel, &$count): void {
                $this->commandDispatcher->dispatch(
                    $account,
                    TelegramOwnerCommandType::VerifySource,
                    ['source_channel_id' => $sourceChannel->id],
                    "source:{$sourceChannel->id}:verify",
                    priority: 80,
                );
                $count++;
            });

        return $count;
    }
}
