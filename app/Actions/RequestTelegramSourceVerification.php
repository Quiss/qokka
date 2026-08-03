<?php

namespace App\Actions;

use App\Models\Source;
use App\Models\TelegramAccount;
use App\Services\TelegramOwnerCommandDispatcher;
use App\TelegramAccountStatus;
use App\TelegramOwnerCommandType;

class RequestTelegramSourceVerification
{
    public function __construct(
        private readonly TelegramOwnerCommandDispatcher $commandDispatcher,
    ) {}

    public function handle(Source $source): int
    {
        if (! $source->isTelegram()) {
            return 0;
        }

        $count = 0;

        TelegramAccount::query()
            ->where('is_active', true)
            ->whereIn('status', [
                TelegramAccountStatus::Authorized,
                TelegramAccountStatus::Connected,
            ])
            ->orderBy('id')
            ->each(function (TelegramAccount $account) use ($source, &$count): void {
                $this->commandDispatcher->dispatch(
                    $account,
                    TelegramOwnerCommandType::VerifySource,
                    ['source_id' => $source->id],
                    "source:{$source->id}:verify",
                    priority: 80,
                );
                $count++;
            });

        return $count;
    }
}
