<?php

namespace App\Services;

use App\Models\SourceMessage;
use App\Models\TelegramAccount;

class TelegramMediaDownloadAccountResolver
{
    public function __construct(private readonly MadelineOwnerLease $ownerLease) {}

    public function resolve(SourceMessage $sourceMessage): ?TelegramAccount
    {
        $sourceMessage->loadMissing([
            'telegramAccount',
            'sourceChannel.collectorTelegramAccount',
            'sourceChannel.telegramAccounts',
        ]);
        $sourceChannel = $sourceMessage->sourceChannel;
        $accounts = collect([
            $sourceChannel->collectorTelegramAccount,
            $sourceMessage->telegramAccount,
        ])
            ->filter()
            ->concat($sourceChannel->telegramAccounts)
            ->unique('id');
        $account = $accounts->first(
            fn (TelegramAccount $account): bool => $account->isCollectorReady()
                && $this->ownerLease->isFresh($account->uuid)
                && $sourceChannel->hasAvailableAccessFor($account->id),
        );

        if (! $account instanceof TelegramAccount) {
            return null;
        }

        if ($sourceChannel->collector_telegram_account_id !== $account->id) {
            $sourceChannel->update(['collector_telegram_account_id' => $account->id]);
        }

        return $account;
    }
}
